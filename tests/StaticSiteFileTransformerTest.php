<?php

namespace PhpTek\Exodus\Test;

use SilverStripe\Dev\SapphireTest;
use PhpTek\Exodus\Transform\StaticSiteFileTransformer;
use PhpTek\Exodus\Tool\StaticSiteContentExtractor;
use PhpTek\Exodus\Model\StaticSiteContentSource;
use PhpTek\Exodus\Model\StaticSiteContentItem;
use PhpTek\Exodus\Transform\StaticSiteTransformResult;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;

/**
 *
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 * @todo add tests that excercise duplicationStrategy() with a non-null $parentId param
 */
class StaticSiteFileTransformerTest extends SapphireTest
{
    /**
     * @var string
     */
    private const IMAGES_DIR = ASSETS_PATH . '/images';

    /**
     * @var string
     */
    private const DOCS_DIR = ASSETS_PATH . '/docs';

    /**
     *
     * @var StaticSiteFileTransformer
     */
    protected $transformer;

    /**
     *
     * @var string
     */
    public static $fixture_file = 'StaticSiteContentSource.yml';

    /**
     * Setup
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        // The transformer
        $this->transformer = singleton(StaticSiteFileTransformer::class);

        // Cache dirs
        if (!file_exists(self::IMAGES_DIR)) {
            mkdir(self::IMAGES_DIR, 0777, true);
        }

        if (!file_exists(self::DOCS_DIR)) {
            mkdir(self::DOCS_DIR, 0777, true);
        }
    }

    /**
     * Run once for the whole suite of StaticSiteFileTransformerTest tests
     */
    public function tearDownOnce()
    {
        // Clear all images that have been saved during this test-run
        $this->delTree(ASSETS_PATH . '/test-graphics');

        parent::tearDownOnce();
    }

    /**
     *
     * @param type $dir
     * @return type
     */
    public function delTree($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }

    /**
     *
     */
    public function testBuildFileProperties()
    {
        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test.zzz', 'image/png');
        $this->assertEquals('images/test.png', $processFile->getFilename());

        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test.zzz', 'image/png');
        $this->assertEquals('images/test.png', $processFile->getFilename());

        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test.png', 'image/png');
        $this->assertEquals('images/test.png', $processFile->getFilename());

        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/docs/test.zzz', 'application/pdf');
        $this->assertEquals('docs/test.pdf', $processFile->getFilename());

        // 'unknown' is what's used as the mime-type for parent URLs that are defined by string manipulation, not actual file-analysis
        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test', 'unknown');
        $this->assertFalse($processFile);

        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test.png', 'unknown');
        $this->assertFalse($processFile);

        // Cannot easily match between, and therefore convert using application/msword => .doc
        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test.zzz', 'application/msword');
        $this->assertFalse($processFile);

        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test.zzz', 'image/fake');
        $this->assertFalse($processFile);

        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test.png.gif', 'image/gif');
        $this->assertEquals('images/test-png.gif', $processFile->getFilename());

        $processFile = $this->transformer->buildFileProperties(File::create(), 'http://localhost/images/test.gif.png', 'image/png');
        $this->assertEquals('images/test-gif.png', $processFile->getFilename());
    }

    /**
     * Test what happens when we define what we want to do when encountering duplicates, but:
     * - The URL isn't found in the cache
     *
     * @todo employ some proper mocking
     */
    public function testTransformForURLNotInCacheIsFile()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsImage1');
        $source->urlList()->setAutoCrawl(true);
        $item = StaticSiteContentItem::create($source, '/assets/test-1.png');
        $item->source = $source;

        // Fail becuase test.png isn't found in the url cache
        $this->assertFalse($this->transformer->transform($item, null, 'Skip'));
        $this->assertFalse($this->transformer->transform($item, null, 'Duplicate'));
        $this->assertFalse($this->transformer->transform($item, null, 'Overwrite'));
    }

    /**
     * Test what happens when we define what we want to do when encountering duplicates, but:
     * - The URL represents a Mime-Type which doesn't match our transformer
     *
     * @todo employ some proper mocking
     */
    public function testTransformForURLIsInCacheNotFile()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsImage1');
        $source->urlList()->setAutoCrawl(true);
        $item = StaticSiteContentItem::create($source, '/test-page-44');
        $item->source = $source;

        // Fail becuase we're using a StaticSiteFileTransformer on a Mime-Type of text/html
        $this->assertFalse($this->transformer->transform($item, null, 'Skip'));
        $this->assertFalse($this->transformer->transform($item, null, 'Duplicate'));
        $this->assertFalse($this->transformer->transform($item, null, 'Overwrite'));
    }

    /**
     * Test what happens when we define what we want to do when encountering duplicates, and:
     * - The URL represents a Mime-Type which does match our transformer
     *
     * @todo employ some proper mocking
     */
    public function testTransformForURLIsInCacheIsFileStrategyDuplicate()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsImage2');
        $source->urlList()->setAutoCrawl(true);
        $item = StaticSiteContentItem::create($source, '/test-graphics/my-image.png');
        $item->source = $source;

        // Pass becuase we do want to perform something on the URL
        $this->assertInstanceOf(StaticSiteTransformResult::class, $fileStrategyDup1 = $this->transformer->transform($item, null, 'Duplicate'));
        $this->assertInstanceOf(StaticSiteTransformResult::class, $fileStrategyDup2 = $this->transformer->transform($item, null, 'Duplicate'));

        // Pass becuase regardless of duplication strategy, we should be getting our filenames post-processed.
        $this->assertEquals('assets/test-graphics/my-image.png', $fileStrategyDup1->file->Filename);
        $this->assertEquals('assets/test-graphics/my-image2.png', $fileStrategyDup2->file->Filename);

        /*
         * Files don't duplicate in the same way as pages are. Duplicate images _are_ created, but their
         * existence is tested for slightly differently.
         */
        $this->assertNotEquals($fileStrategyDup1->file->ID, $fileStrategyDup2->file->ID);
    }

    /**
     * Test what happens when we define what we want to do when encountering duplicates, and:
     * - The URL represents a Mime-Type which does match our transformer
     *
     * @todo employ some proper mocking
     */
    public function testTransformForURLIsInCacheIsFileStrategySkip()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsImage3');
        $source->urlList()->setAutoCrawl(true);
        $item = StaticSiteContentItem::create($source, '/assets/test-3.png');
        $item->source = $source;

        // Fail becuase we're simply using the "skip" strategy. Nothing else needs to be done
        $this->assertFalse($this->transformer->transform($item, null, 'Skip'));
    }

    /**
     * Test what happens when we define what we want to do when encountering duplicates, and:
     * - The URL represents a Mime-Type which does match our transformer
     *
     * @todo employ some proper mocking
     */
    public function testTransformForURLIsInCacheIsFileStrategyOverwrite()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsImage4');
        $source->urlList()->setAutoCrawl(true);
        $item = StaticSiteContentItem::create($source, '/test-graphics/her-image.png');
        $item->source = $source;

        // Pass becuase we do want to perform something on the URL
        $this->assertInstanceOf(StaticSiteTransformResult::class, $fileStrategyOvr1 = $this->transformer->transform($item, null, 'Overwrite'));
        $this->assertInstanceOf(StaticSiteTransformResult::class, $fileStrategyOvr2 = $this->transformer->transform($item, null, 'Overwrite'));

        // Pass becuase regardless of duplication strategy, we should be getting our filenames post-processed
        $this->assertEquals('assets/test-graphics/her-image.png', $fileStrategyOvr1->file->Filename);
        $this->assertEquals('assets/test-graphics/her-image2.png', $fileStrategyOvr2->file->Filename);
        // Ids should be the same becuase overwrite really means update
        $this->assertEquals($fileStrategyOvr1->file->ID, $fileStrategyOvr2->file->ID);
    }

    /**
     * Test we get an instance of StaticSiteContentExtractor to use in custom StaticSiteDataTypeTransformer
     * subclasses.
     */
    public function testGetContentFieldsAndSelectorsNonSSType()
    {
        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsImage5');
        $source->urlList()->setAutoCrawl(true);
        $item = StaticSiteContentItem::create($source, '/test-graphics/some-image.png');
        $item->source = $source;

        $this->assertInstanceOf(StaticSiteContentExtractor::class, $this->transformer->getContentFieldsAndSelectors($item, 'Custom'));
        $this->assertNotInstanceOf(StaticSiteContentExtractor::class, $this->transformer->getContentFieldsAndSelectors($item, 'File'));
    }

    /**
     * Test the correct outputs for getDirHierarchy()
     */
    public function testGetDirHierarchy()
    {
        $transformer = singleton(StaticSiteFileTransformer::class);
        $this->assertEquals('images/subdir-1', $transformer->getDirHierarchy('http://test.com/images/subdir-1/test.png', false));
        $this->assertEquals('images/subdir-1', $transformer->getDirHierarchy('http://www.test.com/images/subdir-1/test.png', false));
        $this->assertEquals('images/subdir-1', $transformer->getDirHierarchy('https://www.test.com/images/subdir-1/test.png', false));
        $this->assertEquals('images/subdir-1', $transformer->getDirHierarchy('https://www.test.com/images//subdir-1/test.png', false));
        $this->assertEquals('', $transformer->getDirHierarchy('https://www.test.com/test.png', false));

        $this->assertEquals(BASE_PATH . '/assets/images/subdir-1', $transformer->getDirHierarchy('http://test.com/images/subdir-1/test.png', true));
        $this->assertEquals(BASE_PATH . '/assets/images/subdir-1', $transformer->getDirHierarchy('http://www.test.com/images/subdir-1/test.png', true));
        $this->assertEquals(BASE_PATH . '/assets/images/subdir-1', $transformer->getDirHierarchy('https://www.test.com/images/subdir-1/test.png', true));
        $this->assertEquals(BASE_PATH . '/assets/images/subdir-1', $transformer->getDirHierarchy('https://www.test.com/images//subdir-1/test.png', true));
        $this->assertEquals(BASE_PATH . '/assets', $transformer->getDirHierarchy('https://www.test.com/test.png', true));
    }

    /**
     * Tests our custom file-versioning works correctly
     */
    public function testVersionFile()
    {
        $transformer = singleton(StaticSiteFileTransformer::class);

        $source = $this->objFromFixture(StaticSiteContentSource::class, 'MyContentSourceIsImage6');
        $source->urlList()->setAutoCrawl(true);
        $item = StaticSiteContentItem::create($source, '/test-graphics/foo-image.jpg');
        $item->source = $source;

        // Save an initial version of an image
        $this->transformer->transform($item, null, 'Skip');
        // Version it
        $versioned = $transformer->versionFile('test-graphics/foo-image.jpg');
        // Test it
        $this->assertEquals('test-graphics/foo-image2.jpg', $versioned);
    }
}
