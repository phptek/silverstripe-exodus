<?php

namespace PhpTek\Exodus\Test;

use SilverStripe\Dev\SapphireTest;
use PhpTek\Exodus\Processor\StaticSiteURLProcessorDropExtensions;
use PhpTek\Exodus\Processor\StaticSiteMOSSURLProcessor;

/**
 *
 * @author Russell Michell <russ@theruss.com>
 * @package phptek/silverstripe-exodus
 */
class StaticSiteUrlProcessorTest extends SapphireTest
{
    /**
     * Tests StaticSiteURLProcessorDropExtensions URL Processor
     */
    public function testStaticSiteURLProcessorDropExtensions()
    {
        $processor = StaticSiteURLProcessorDropExtensions::create();

        $this->assertEmpty($processor->processUrl([]));

        $testUrl4CharSufx = $processor->processUrl([
            'url' => 'http://fluff.com/test1.html',
            'mime' => 'text/html'
        ]);
        $this->assertEquals('http://fluff.com/test1', $testUrl4CharSufx['url']);

        $testUrl3CharSufx = $processor->processUrl([
            'url' => 'http://fluff.com/test2.htm',
            'mime' => 'text/html'
        ]);
        $this->assertEquals('http://fluff.com/test2', $testUrl3CharSufx['url']);

        $testUrlNoCharSufx = $processor->processUrl([
            'url' => 'http://fluff.com/test3',
            'mime' => 'text/html'
        ]);
        $this->assertEquals('http://fluff.com/test3', $testUrlNoCharSufx['url']);

        $testUrlWithBrackets = $processor->processUrl([
            'url' => 'http://fluff.com/test3/(subdir)',
            'mime' => 'text/html'
        ]);
        $this->assertEquals('http://fluff.com/test3/subdir', $testUrlWithBrackets['url']);
    }

    /**
     * Tests StaticSiteURLProcessorDropExtensions URL Processor
     */
    public function testStaticSiteMOSSURLProcessor()
    {
        $processor = StaticSiteMOSSURLProcessor::create();

        $this->assertEmpty($processor->processUrl([]));

        $testUrlWithPages = $processor->processUrl([
            'url' => 'http://fluff.com/Pages/test1.aspx',
            'mime' => 'text/html'
        ]);
        $this->assertEquals('http://fluff.com/test1', $testUrlWithPages['url']);

        $testUrlWithBrackets = $processor->processUrl([
            'url' => 'http://fluff.com/Pages/test(1).aspx',
            'mime' => 'text/html'
        ]);
        $this->assertEquals('http://fluff.com/test1', $testUrlWithPages['url']);

        $testUrlWithSingleBracket = $processor->processUrl([
            'url' => 'http://fluff.com/Pages/test(1.aspx',
            'mime' => 'text/html'
        ]);
        $this->assertEquals('http://fluff.com/test1', $testUrlWithPages['url']);

        $testUrlWithRepeatedBracket = $processor->processUrl([
            'url' => 'http://fluff.com/Pages/test((1.aspx',
            'mime' => 'text/html'
        ]);
        $this->assertEquals('http://fluff.com/test1', $testUrlWithPages['url']);
    }
}
