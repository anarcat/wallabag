<?php

namespace Tests\Wallabag\ImportBundle\Controller;

use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class InstapaperControllerTest extends WallabagCoreTestCase
{
    public function testImportInstapaper()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/instapaper');

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals(1, $crawler->filter('form[name=upload_import_file] > button[type=submit]')->count());
        $this->assertEquals(1, $crawler->filter('input[type=file]')->count());
    }

    public function testImportInstapaperWithRabbitEnabled()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $client->getContainer()->get('craue_config')->set('import_with_rabbitmq', 1);

        $crawler = $client->request('GET', '/import/instapaper');

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals(1, $crawler->filter('form[name=upload_import_file] > button[type=submit]')->count());
        $this->assertEquals(1, $crawler->filter('input[type=file]')->count());

        $client->getContainer()->get('craue_config')->set('import_with_rabbitmq', 0);
    }

    public function testImportInstapaperBadFile()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/instapaper');
        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $data = [
            'upload_import_file[file]' => '',
        ];

        $client->submit($form, $data);

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testImportInstapaperWithRedisEnabled()
    {
        $this->checkRedis();
        $this->logInAs('admin');
        $client = $this->getClient();
        $client->getContainer()->get('craue_config')->set('import_with_redis', 1);

        $crawler = $client->request('GET', '/import/instapaper');

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals(1, $crawler->filter('form[name=upload_import_file] > button[type=submit]')->count());
        $this->assertEquals(1, $crawler->filter('input[type=file]')->count());

        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $file = new UploadedFile(__DIR__.'/../fixtures/instapaper-export.csv', 'instapaper.csv');

        $data = [
            'upload_import_file[file]' => $file,
        ];

        $client->submit($form, $data);

        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.import.notice.summary', $body[0]);

        $this->assertNotEmpty($client->getContainer()->get('wallabag_core.redis.client')->lpop('wallabag.import.instapaper'));

        $client->getContainer()->get('craue_config')->set('import_with_redis', 0);
    }

    public function testImportInstapaperWithFile()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/instapaper');
        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $file = new UploadedFile(__DIR__.'/../fixtures/instapaper-export.csv', 'instapaper.csv');

        $data = [
            'upload_import_file[file]' => $file,
        ];

        $client->submit($form, $data);

        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.import.notice.summary', $body[0]);

        $content = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Entry')
            ->findByUrlAndUserId(
                'http://www.liberation.fr/societe/2012/12/06/baumettes-un-tour-en-cellule_865551',
                $this->getLoggedInUserId()
            );

        $this->assertNotEmpty($content->getMimetype(), 'Mimetype for http://www.liberation.fr is ok');
        $this->assertNotEmpty($content->getPreviewPicture(), 'Preview picture for http://www.liberation.fr is ok');
        $this->assertNotEmpty($content->getLanguage(), 'Language for http://www.liberation.fr is ok');
        $this->assertContains('foot', $content->getTags(), 'It includes the "foot" tag');
        $this->assertEquals(1, count($content->getTags()));
        $this->assertInstanceOf(\DateTime::class, $content->getCreatedAt());

        $content = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Entry')
            ->findByUrlAndUserId(
                'http://www.20minutes.fr/high-tech/2077615-20170531-dis-donc-donald-trump-quoi-exactement-covfefe',
                $this->getLoggedInUserId()
            );

        $this->assertContains('foot', $content->getTags());
        $this->assertContains('test_tag', $content->getTags());

        $this->assertEquals(2, count($content->getTags()));
    }

    public function testImportInstapaperWithFileAndMarkAllAsRead()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/instapaper');
        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $file = new UploadedFile(__DIR__.'/../fixtures/instapaper-export.csv', 'instapaper-read.csv');

        $data = [
            'upload_import_file[file]' => $file,
            'upload_import_file[mark_as_read]' => 1,
        ];

        $client->submit($form, $data);

        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $content1 = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Entry')
            ->findByUrlAndUserId(
                'https://redditblog.com/2016/09/20/amp-and-reactredux/',
                $this->getLoggedInUserId()
            );

        $this->assertTrue($content1->isArchived());

        $content2 = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Entry')
            ->findByUrlAndUserId(
                'https://medium.com/@the_minh/why-foursquare-swarm-is-still-my-favourite-social-network-e38228493e6c',
                $this->getLoggedInUserId()
            );

        $this->assertTrue($content2->isArchived());

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.import.notice.summary', $body[0]);
    }

    public function testImportInstapaperWithEmptyFile()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/instapaper');
        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $file = new UploadedFile(__DIR__.'/../fixtures/test.txt', 'test.txt');

        $data = [
            'upload_import_file[file]' => $file,
        ];

        $client->submit($form, $data);

        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.import.notice.failed', $body[0]);
    }
}
