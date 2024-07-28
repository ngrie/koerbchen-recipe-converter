<?php

declare(strict_types=1);

namespace Ngrie\KoerbchenRecipeConverter\Command;

use Ngrie\KoerbchenRecipeConverter\Util\FirebaseHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\UuidV4;

final class ConvertCommand extends Command
{
    private static array $unitMapping = [
        'g' => 'GRAMS',
        'kg' => 'KGS',
        'ml' => 'MILLS',
        'l' => 'LITRES',
        'cl' => 'CENTILITER',
        'dl' => 'DECILITER',
        'EL' => 'TABLESPOON',
        'TL' => 'TEASPOON',
        'Prise(n)' => 'PINCH',
        'Bund' => 'BUNCH',
        'Dose(n)' => 'CAN',
        'Becher' => 'CUP',
        'Pck.' => 'PACKET',
        'Flasche(n)' => 'BOTTLE',
        'Stück(e)' => 'ITEM',
        'St' => 'ITEM',
        'Pkt.' => 'PACKET',
    ];

    private array $unmappedUnits = [];

    public function __construct(
        private string $inputDirectory,
        private string $outputDirectory,
        private string $imagesDirectory,
    )
    {
        parent::__construct('convert');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fs = new Filesystem();

        $cookbooks = $this->getCookbooks();
        $recipes = $this->getRecipes();
        $io->info(sprintf('Found %d recipes', count($recipes)));

        foreach ($cookbooks as $cookbook) {
            foreach ($cookbook['recipes'] as $recipe) {
                if (!array_key_exists($recipe, $recipes)) {
                    continue;
                }

                $recipes[$recipe]['cookbooks'][] = $cookbook['name'];
            }
        }

        $fs->mkdir($this->outputDirectory);
        file_put_contents($this->outputDirectory . '/recipes.json', json_encode($recipes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $fs->mkdir($this->imagesDirectory);
        $io->info('Downloading images ...');
        $this->downloadImages($recipes, $fs, $io);
        $io->info('... done');

        $i = 1;
        $slugger = new AsciiSlugger();
        foreach ($recipes as $recipe) {
            $cs = $this->createCroutonStructure($recipe, $fs);
            file_put_contents(sprintf('%s/%d-%s.crumb', $this->outputDirectory, $i, $slugger->slug($recipe['title'])->toString()), json_encode($cs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $i++;
        }

        $io->warning('Unknown units: ' . implode(', ', array_keys($this->unmappedUnits)));

        return Command::SUCCESS;
    }

    private function downloadImages(array $recipes, Filesystem $fs, SymfonyStyle $io): void
    {
        $client = HttpClient::create();
        $responses = [];
        foreach ($recipes as $recipe) {
            foreach ($recipe['images'] as $image) {
                if (str_contains($image['url'], 'placeholder_recipe.jpg')) {
                    continue;
                }
                if ($fs->exists($this->imagesDirectory . '/' . $image['id'])) {
                    continue;
                }

                $responses[$image['id']] = $client->request('GET', $image['url']);
            }
        }

        foreach ($responses as $id => $response) {
            if ($response->getStatusCode() !== 200) {
                $io->warning(sprintf('Got status code %d for %s', $response->getStatusCode(), $response->getInfo('url')));
                continue;
            }

            file_put_contents($this->imagesDirectory . '/' . $id, base64_encode($response->getContent()));
        }
    }

    private function createCroutonStructure(array $recipe, Filesystem $fs): array
    {
        $nutritionData = null !== ($recipe['nutrition'] ?? null) ? array_filter($recipe['nutrition']) : null;
        $nutrition = null;
        if (null !== $nutritionData) {
            $nutrition = [];
            if (array_key_exists('carbohydrate', $nutritionData)) {
                $nutrition[] = sprintf('Kohlenhydrate: %sg', $nutritionData['carbohydrate']);
            }
            if (array_key_exists('calories', $nutritionData)) {
                $nutrition[] = sprintf('Kalorien: %s kcal', $nutritionData['calories']);
            }
            if (array_key_exists('protein', $nutritionData)) {
                $nutrition[] = sprintf('Protein: %sg', $nutritionData['protein']);
            }
            if (array_key_exists('fat', $nutritionData)) {
                $nutrition[] = sprintf('Fett: %sg', $nutritionData['fat']);
            }
            $nutrition[] = 'Serviergröße: ' . $recipe['dishes'];
        }

        return [
            'uuid' => self::generateUuid(),
            'name' => $recipe['title'],
            'serves' => $recipe['dishes'],
            'defaultScale' => $recipe['scaledDishes'],
            'duration' => null,
            'cookingDuration' => $recipe['totalTime'],
            'webLink' => $recipe['source'] ?? null,
            'neutritionalInfo' => null !== $nutrition ? implode(",\n", $nutrition) : null,
            'senderName' => 'Körbchen',
            'sourceName' => null !== $recipe['source'] ? ucfirst(str_replace('www.', '', parse_url($recipe['source'], PHP_URL_HOST))) : null,
            'ingredients' => $this->buildIngredients($recipe['ingredients']),
            'steps' => $this->buildSteps($recipe['instructions']),
            'isPublicRecipe' => false,
            'tags' => [], // $recipe['tags'],
            'folderIDs' => [],
            'images' => array_map(
                fn (array $image) => file_get_contents($this->imagesDirectory . '/' . $image['id']),
                array_values(array_filter($recipe['images'], fn (array $image) => $fs->exists($this->imagesDirectory . '/' . $image['id']))),
            ),
        ];
    }

    private function buildIngredients(array $ingredients): array
    {
        $result = [];
        $i = 0;
        foreach ($ingredients as $group) {
            if (null !== $group['title'] && count($ingredients) > 1) {
                $result[] = [
                    'uuid' => self::generateUuid(),
                    'order' => $i,
                    'ingredient' => [
                        'uuid' => self::generateUuid(),
                        'name' => $group['title'],
                    ],
                    'quantity' => ['quantityType' => 'SECTION'],
                ];
                $i++;
            }

            foreach ($group['ingredients'] as $ingredient) {
                $name = $ingredient['name'];
                if (null !== $ingredient['unit'] && !array_key_exists($ingredient['unit'], self::$unitMapping)) {
                    $this->unmappedUnits[$ingredient['unit']] = true;
                    $name = $ingredient['unit'] . ' ' . $name;
                }

                $result[] = [
                    'uuid' => self::generateUuid(),
                    'order' => $i,
                    'ingredient' => [
                        'uuid' => self::generateUuid(),
                        'name' => trim($name),
                    ],
                    'quantity' => [
                        'quantityType' => self::$unitMapping[$ingredient['unit']] ?? 'ITEM',
                        'amount' => $ingredient['amount'],
                    ],
                ];
                $i++;
            }
        }

        return $result;
    }

    private function buildSteps(array $steps): array
    {
        $result = [];
        $i = 0;
        foreach ($steps as $step) {
            if (null !== $step['title'] && ($i > 0 || 'Zubereitung' !== $step['title'])) {
                $result[] = [
                    'uuid' => self::generateUuid(),
                    'order' => $i,
                    'isSection' => true,
                    'step' => $step['title'],
                ];
                $i++;
            }

            if (null !== $step['text']) {
                $result[] = [
                    'uuid' => self::generateUuid(),
                    'order' => $i,
                    'isSection' => false,
                    'step' => $step['text'],
                ];
                $i++;
            }
        }

        return $result;
    }

    private function getRecipes(): array
    {
        $recipesInputData = json_decode(file_get_contents($this->inputDirectory . '/recipes.json'), true, 512, JSON_THROW_ON_ERROR);

        $recipes = [];
        foreach ($recipesInputData as $item) {
            $id = FirebaseHelper::getValue($item['document']['fields']['id']);
            $title = FirebaseHelper::getValue($item['document']['fields']['title']);
            if (null === $title) {
                throw new \RuntimeException(sprintf('Recipe %s has empty title.', $id));
            }
            $instructionsData = null !== FirebaseHelper::getValue($item['document']['fields']['instructions']) ? $item['document']['fields']['instructions']['arrayValue']['values'] : [];
            $recipes[$id] = [
                'id' => $id,
                'title' => FirebaseHelper::getValue($item['document']['fields']['title']),
                'dishesTitle' => FirebaseHelper::getValue($item['document']['fields']['dishesTitle'] ?? null),
                'instructions' => array_map(
                    static fn (array $instruction) => [
                        'id' => FirebaseHelper::getValue($instruction['mapValue']['fields']['id']),
                        'title' => FirebaseHelper::getValue($instruction['mapValue']['fields']['title']),
                        'text' => FirebaseHelper::getValue($instruction['mapValue']['fields']['text']),
                    ],
                    $instructionsData,
                ),
                'images' => array_map(
                    static fn (array $image) => [
                        'id' => (new AsciiSlugger())->slug(FirebaseHelper::getValue($image['mapValue']['fields']['url']))->toString(),
                        'author' => FirebaseHelper::getValue($image['mapValue']['fields']['author']),
                        'url' => FirebaseHelper::getValue($image['mapValue']['fields']['url']),
                    ],
                    null !== FirebaseHelper::getValue($item['document']['fields']['images']) ? $item['document']['fields']['images']['arrayValue']['values'] : [],
                ),
                'totalTime' => FirebaseHelper::getValue($item['document']['fields']['totalTime']),
                'rating' => FirebaseHelper::getValue($item['document']['fields']['rating']),
                'dishes' => FirebaseHelper::getValue($item['document']['fields']['dishes']),
                'scaledDishes' => FirebaseHelper::getValue($item['document']['fields']['scaledDishes']),
                'source' => FirebaseHelper::getValue($item['document']['fields']['source']),
                'nutrition' => null !== FirebaseHelper::getValue($item['document']['fields']['nutrition'] ?? null) ? array_map(
                    FirebaseHelper::getValue(...),
                    $item['document']['fields']['nutrition']['mapValue']['fields'],
                ) : null,
                'ingredients' => array_map(
                    static fn ($item) => [
                        'title' => FirebaseHelper::getValue($item['mapValue']['fields']['title']),
                        'ingredients' => array_map(
                            static fn (array $ingredient) => [
                                'name' => FirebaseHelper::getValue($ingredient['mapValue']['fields']['product']['mapValue']['fields']['name']),
                                'amount' => FirebaseHelper::getValue($ingredient['mapValue']['fields']['amount']),
                                'unit' => null !== FirebaseHelper::getValue($ingredient['mapValue']['fields']['unit']) ? FirebaseHelper::getValue($ingredient['mapValue']['fields']['unit']['mapValue']['fields']['name']) : null,
                            ],
                            null !== FirebaseHelper::getValue($item['mapValue']['fields']['ingredients']) ? $item['mapValue']['fields']['ingredients']['arrayValue']['values'] : [],
                        ),
                    ],
                    null !== FirebaseHelper::getValue($item['document']['fields']['ingredientLists']) ? $item['document']['fields']['ingredientLists']['arrayValue']['values'] : [],
                ),
                'tags' => null !== FirebaseHelper::getValue($item['document']['fields']['categories']) ? array_map(
                    FirebaseHelper::getValue(...),
                    $item['document']['fields']['categories']['arrayValue']['values'],
                ) : null,
                'cookbooks' => [],
                'createdAt' => FirebaseHelper::getValue($item['document']['fields']['createdAt']),
            ];
        }

        return $recipes;
    }

    private function getCookbooks(): array
    {
        $cookbooksInputData = json_decode(file_get_contents($this->inputDirectory . '/cookbooks.json'), true, 512, JSON_THROW_ON_ERROR);

        $cookbooks = [];
        foreach ($cookbooksInputData as $item) {
            $id = FirebaseHelper::getValue($item['document']['fields']['id']);
            $cookbooks[$id] = [
                'id' => $id,
                'name' => FirebaseHelper::getValue($item['document']['fields']['name']),
                'recipes' => array_map(FirebaseHelper::getValue(...), $item['document']['fields']['recipeIds']['arrayValue']['values']),
            ];
        }

        return $cookbooks;
    }

    private static function generateUuid(): string
    {
        return strtoupper((new UuidV4())->toRfc4122());
    }
}
