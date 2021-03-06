<?php

/**
 * @file
 * Contains BeanstalkdServerTest.
 */

namespace Drupal\beanstalkd\Tests;

use Pheanstalk\Job;

/**
 * Class BeanstalkdServerTest.
 *
 * @group Beanstalkd
 */
class BeanstalkdServerTest extends BeanstalkdTestBase {

  /**
   * Test creating an item on an un-managed queue.
   */
  public function testCreateSad() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube, $start_count) = $this->initServerWithTube();
    $server->releaseTube($tube);

    $job_id = $server->putData($tube, 'foo');
    $this->assertEquals(0, $job_id, 'Creating an item in an unhandled queue does not return a valid job id.');

    $server->addTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count;
    $this->assertEquals($expected, $actual, 'Creating an item in an unhandled queue does not actually submit it');

    $this->cleanUp($server, $tube);
  }

  /**
   * Test item deletion.
   */
  public function testDelete() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube, $start_count) = $this->initServerWithTube();

    // Avoid any "ground-effect" during tests with counts near 0.
    $create_count = 5;

    $job_id = 0;
    for ($i = 0; $i < $create_count; $i++) {
      $job_id = $server->putData($tube, 'foo' . $i);
    }

    $expected = $start_count + $create_count;
    $actual = $server->getTubeItemCount($tube);
    $this->assertEquals($expected, $actual);

    // This should not do anything, since the queue name is incorrect.
    $server->deleteJob($tube . $tube, $job_id);
    $this->assertEquals($expected, $actual);

    $server->deleteJob($tube, $job_id);
    $expected = $start_count + $create_count - 1;
    $actual = $server->getTubeItemCount($tube);
    $this->assertEquals($expected, $actual, 'Deletion actually deletes jobs.');

    $this->cleanUp($server, $tube);
  }

  /**
   * Tests tube flushing.
   */
  public function testFlush() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube,) = $this->initServerWithTube();
    $item = 'foo';
    $server->putData($tube, $item);
    $server->flushTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $this->assertEquals(0, $actual, 'Tube is empty after flushTube');

    $server->removeTube($tube);
    $this->assertEquals(0, $actual, 'Tube is empty after removeTube');

    $this->cleanUp($server, $tube);
  }

  /**
   * Tests flushing an un-managed queue: should not error, and should return 0.
   */
  public function testFlushSad() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube, $start_count) = $this->initServerWithTube();
    $server->putData($tube, 'foo');

    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($actual, $expected, 'Tube is not empty before flush');

    $server->releaseTube($tube);

    // Flush should pretend to succeed on a un-managed queue.
    $server->flushTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $this->assertEquals(0, $actual, 'Tube is shown as empty after flushing an un-managed tube');

    // But it should not actually have performed a flush.
    $server->addTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($expected, $actual, 'Tube is actually not empty after flushing an un-managed tube.');

    $this->cleanUp($server, $tube);
  }

  /**
   * Test item release.
   */
  public function testRelease() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube, $start_count) = $this->initServerWithTube();
    $server->putData($tube, 'foo');
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($expected, $actual);

    // Just-submitted job should be present.
    $job = $server->claimJob($tube);
    $this->assertTrue(is_object($job) && $job instanceof Job, 'claimJob returns a Job');

    if (is_object($job) && $job instanceof Job) {
      // Claiming an item removes it from the visible count.
      $actual = $server->getTubeItemCount($tube);
      $expected = $start_count;
      $this->assertEquals($expected, $actual);

      // Releasing it makes it available again.
      $server->releaseJob($tube, $job);
      $actual = $server->getTubeItemCount($tube);
      $expected = $start_count + 1;
      $this->assertEquals($expected, $actual);
    }

    $this->cleanUp($server, $tube);
  }

  /**
   * Test item release sad: releaseJob() on a un-managed queue does nothing.
   */
  public function testReleaseSad() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube, $start_count) = $this->initServerWithTube();
    $data = 'foo';
    $server->putData($tube, $data);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count + 1;
    $this->assertEquals($expected, $actual);

    // Just-submitted job should not be available from an un-managed queue.
    $server->releaseTube($tube);
    $job = $server->claimJob($tube);
    $this->assertSame(FALSE, $job, 'claimJob returns nothing from an un-managed queue');

    // But it should still be there.
    $server->addTube($tube);
    $job = $server->claimJob($tube);
    $this->assertTrue(is_object($job) && $job instanceof Job, 'claimJob returns a Job');

    // And it should not be included in the visible count.
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count;
    $this->assertEquals($expected, $actual);

    // Releasing it does not makes it available if the queue is not managed.
    $server->releaseTube($tube);
    if (is_object($job) && $job instanceof Job) {
      $server->releaseJob($tube, $job);
    }
    // Queue is re-handled to get the actual available count.
    $server->addTube($tube);
    $actual = $server->getTubeItemCount($tube);
    $expected = $start_count;
    $this->assertEquals($expected, $actual);

    $this->cleanUp($server, $tube);
  }

  /**
   * Test the various stats() sub-commands in normal situations.
   */
  public function testStatsHappy() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube,) = $this->initServerWithTube();

    $data = 'foo';
    $job_id = $server->putData($tube, $data);
    $job = new Job($job_id, NULL);

    $stats = $server->stats('global');
    $this->assertTrue($stats instanceof \ArrayObject, 'Global stats implements ArrayObject');
    $this->assertGreaterThan(1, count($stats), 'Global stats contain more than one item.');

    $stats = $server->stats('tube', $tube);
    $this->assertTrue($stats instanceof \ArrayObject, 'Tube stats implements ArrayObject');
    $this->assertGreaterThan(1, count($stats), 'Tube stats contain more than one item.');

    $stats = $server->stats('job', $tube, $job);
    $this->assertTrue($stats instanceof \ArrayObject, 'Job stats implements ArrayObject');
    $this->assertGreaterThan(1, count($stats), 'Job stats contain more than one item.');

    $this->cleanUp($server, $tube);
  }

  /**
   * Test the various stats() sub-commands in abnormal situations.
   */
  public function testStatsSad() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube,) = $this->initServerWithTube();

    $data = 'foo';
    $job_id = $server->putData($tube, $data);
    $job = new Job($job_id, NULL);

    try {
      $server->stats('invalid');
      $this->fail('Asking for incorrect statistics does not throw an exception');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertTrue(TRUE, 'Asking for incorrect statistics throws the expected exception');
    }
    catch (\Exception $e) {
      $this->fail('Asking for incorrect statistics throws incorrect exception');
    }

    $stats = $server->stats('job', $tube);
    $this->assertFalse($stats, 'Asking for job stats for null job returns FALSE');

    $stats = $server->stats('job', $tube . $tube, $job);
    $this->assertFalse($stats, 'Asking for job stats for correct job on incorrect tube returns FALSE');

    $this->cleanUp($server, $tube);
  }

  /**
   * Test tube listing.
   */
  public function testTubes() {
    /* @var \Drupal\beanstalkd\Server\BeanstalkdServer $server */
    list($server, $tube,) = $this->initServerWithTube();

    $initial_tubes = $server->listTubes();
    $initial_count = count($initial_tubes);
    $extra_count = mt_rand(1, 5);

    $tubes = [];
    for ($i = 0; $i < $extra_count; $i++) {
      $extra_tube = 'tube-' . $i;
      $tubes[] = $extra_tube;
      $server->addTube($extra_tube);
    }
    $server->addWatches($tubes);

    $listed = $server->listTubes();

    $actual = count($listed);
    // Add 1 for the default tube.
    $expected = $initial_count + $extra_count;
    $this->assertEquals($expected, $actual, 'The correct number of tubes is found');

    $actual = $listed;
    sort($listed);
    $expected = array_merge($initial_tubes, $tubes);
    sort($tubes);
    $this->assertEquals($expected, $actual, 'The correct tubes are found.');

    // Only remove tubes created for the test.
    foreach ($tubes as $extra_tube) {
      $server->releaseTube($extra_tube);
    }

    $this->cleanUp($server, $tube);
  }

}
