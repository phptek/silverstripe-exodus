<?php
/**
 * 
 * @author Russell Michell <russ@silverstripe.com>
 * @package staticsiteconnector
 */
class StaticSiteRewriteLinksTaskTest extends SapphireTest {
	
	public function testLinkIsThirdParty() {
		$task = singleton('StaticSiteRewriteLinksTask');
		
		$this->assertTrue($task->linkIsThirdParty('http://test.com'));
		$this->assertTrue($task->linkIsThirdParty('http://test.com/subdir/test.html'));
		$this->assertTrue($task->linkIsThirdParty('https://test.com/subdir/test.html'));
		$this->assertFalse($task->linkIsThirdParty('https://')); // Will be registered as junk
		$this->assertFalse($task->linkIsThirdParty('https://  ')); // Will be registered as junk
		$this->assertFalse($task->linkIsThirdParty('/subdir/test.html'));
	}

	public function testLinkIsBadScheme() {
		$task = singleton('StaticSiteRewriteLinksTask');
		
		$this->assertTrue($task->linkIsBadScheme('tel://021111111'));
		$this->assertTrue($task->linkIsBadScheme('ssh://192.168.1.1'));
		$this->assertTrue($task->linkIsBadScheme('htp://fluff.com/'));
	}	
	
	public function testLinkIsNotImported() {
		$task = singleton('StaticSiteRewriteLinksTask');
		
		$this->assertTrue($task->linkIsNotImported('/'));
		//$this->assertTrue($task->linkIsNotImported('/fluff'));
		//$this->assertTrue($task->linkIsNotImported('/fluff.html'));
		$this->assertFalse($task->linkIsNotImported('[sitetree'));
		$this->assertFalse($task->linkIsNotImported('/assets/test.pdf'));
	}		
	
}
