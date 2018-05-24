<?php declare(strict_types=1);

namespace Shopware\Framework\Test\ORM\Dbal;

use Doctrine\DBAL\Connection;
use Shopware\Application\Context\Struct\ApplicationContext;
use Shopware\Content\Category\CategoryRepository;
use Shopware\Defaults;
use Shopware\Framework\ORM\Dbal\Indexing\ChildCountIndexer;
use Shopware\Framework\Struct\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ChildCountIndexerTest extends KernelTestCase
{
    /**
     * @var CategoryRepository
     */
    private $categoryRepository;

    /**
     * @var ApplicationContext
     */
    private $context;

    /**
     * @var ChildCountIndexer
     */
    private $childCountIndexer;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var Connection
     */
    private $connection;

    public function setUp()
    {
        self::bootKernel();
        $this->categoryRepository = self::$container->get(CategoryRepository::class);
        $this->context = ApplicationContext::createDefaultContext(Defaults::TENANT_ID);
        $this->childCountIndexer = self::$container->get(ChildCountIndexer::class);
        $this->eventDispatcher = self::$container->get('event_dispatcher');
        $this->connection = self::$container->get(Connection::class);
    }

    public function testCreateChildCategory()
    {
        /*
        Category A
        ├── Category B
        ├── Category C
        │  └── Category D
        */
        $categoryA = $this->createCategory();

        $categoryB = $this->createCategory($categoryA);
        $categoryC = $this->createCategory($categoryA);

        $categoryD = $this->createCategory($categoryC);

        $categories = $this->categoryRepository->readBasic([$categoryA, $categoryB, $categoryC, $categoryD], $this->context);

        $this->assertEquals(2, $categories->get($categoryA)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryB)->getChildCount());
        $this->assertEquals(1, $categories->get($categoryC)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryD)->getChildCount());

        $this->categoryRepository->update([[
            'id' => $categoryD,
            'parentId' => $categoryA,
        ]], $this->context);

        /*
        Category A
        ├── Category B
        ├── Category C
        ├── Category D
        */

        $categories = $this->categoryRepository->readBasic([$categoryA, $categoryB, $categoryC, $categoryD], $this->context);

        $this->assertEquals(3, $categories->get($categoryA)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryB)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryC)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryD)->getChildCount());
    }

    public function testChildCountCategoryMovingMultipleCategories()
    {
        /*
        Category A
        ├── Category B
        │  └── Category C
        ├── Category D
        │  └── Category E
        */
        $categoryA = $this->createCategory();
        $categoryB = $this->createCategory($categoryA);
        $categoryC = $this->createCategory($categoryB);

        $categoryD = $this->createCategory($categoryA);
        $categoryE = $this->createCategory($categoryD);

        $categories = $this->categoryRepository->readBasic(
            [$categoryA, $categoryB, $categoryC, $categoryD, $categoryE],
            $this->context
        );

        $this->assertEquals(2, $categories->get($categoryA)->getChildCount());
        $this->assertEquals(1, $categories->get($categoryB)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryC)->getChildCount());
        $this->assertEquals(1, $categories->get($categoryD)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryE)->getChildCount());

        $this->categoryRepository->update([
            [
                'id' => $categoryC,
                'parentId' => $categoryA,
            ],
            [
                'id' => $categoryD,
                'parentId' => $categoryC,
            ],
            [
                'id' => $categoryE,
                'parentId' => $categoryC,
            ],
        ], $this->context);

        /**
        Category A
        ├── Category B
        ├── Category C
        │  └── Category D
        │  └── Category E
         */
        $categories = $this->categoryRepository->readBasic(
            [$categoryA, $categoryB, $categoryC, $categoryD, $categoryE],
            $this->context
        );

        $this->assertEquals(2, $categories->get($categoryA)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryB)->getChildCount());
        $this->assertEquals(2, $categories->get($categoryC)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryD)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryE)->getChildCount());
    }

    public function testChildCountIndexer()
    {
        /*
        Category A
        ├── Category B
        ├── Category C
        │  └── Category D
        */
        $categoryA = $this->createCategory();

        $categoryB = $this->createCategory($categoryA);
        $categoryC = $this->createCategory($categoryA);

        $categoryD = $this->createCategory($categoryC);

        $this->connection->executeQuery(
            'UPDATE category SET child_count = 0 WHERE HEX(id) IN (:ids)',
            [
                'ids' => [
                    $categoryA,
                    $categoryB,
                    $categoryC,
                    $categoryD,
                ],
            ],
            ['ids' => Connection::PARAM_STR_ARRAY]
        );

        $categories = $this->categoryRepository->readBasic([$categoryA, $categoryB, $categoryC, $categoryD], $this->context);
        foreach ($categories as $category) {
            $this->assertEquals(0, $category->getChildCount());
        }

        $this->childCountIndexer->index(new \DateTime(), $this->context->getTenantId());

        $categories = $this->categoryRepository->readBasic([$categoryA, $categoryB, $categoryC, $categoryD], $this->context);

        $this->assertEquals(2, $categories->get($categoryA)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryB)->getChildCount());
        $this->assertEquals(1, $categories->get($categoryC)->getChildCount());
        $this->assertEquals(0, $categories->get($categoryD)->getChildCount());
    }

    private function createCategory(string $parentId = null)
    {
        $id = Uuid::uuid4()->getHex();
        $data = [
            'id' => $id,
            'catalogId' => Defaults::CATALOG,
            'name' => 'Category ',
        ];

        if ($parentId) {
            $data['parentId'] = $parentId;
        }
        $this->categoryRepository->upsert([$data], $this->context);

        return $id;
    }
}
