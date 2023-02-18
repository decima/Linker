<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Lock\LockFactory;

class LinkManager
{

    public function __construct(private readonly LockFactory $lockFactory, private readonly LoggerInterface $logger, private readonly string $baseDir = "")
    {
        @mkdir($this->baseDir, permissions: 0777, recursive: true);
    }

    public function listResources()
    {
        $finder = new Finder();
        $this->logger->info("listing all categories");
        return $finder->files()->in($this->baseDir);
    }

    public function store($resourceName, $url)
    {

        $lock = $this->lockFactory->createLock('file-' . $resourceName);
        $this->logger->info("locking category $resourceName", ["category" => $resourceName]);

        $lock->acquire(true);
        $resource = $this->getResource($resourceName);

        $content = [];

        try {
            $content = $this->getDetails($url);
        } catch (\Throwable $throwable) {
            $this->logger->error("unable to get url content", ["category" => $resourceName, "url" => $url]);
            $this->logger->debug($throwable->getMessage());
        }

        $content['url'] = $url;
        $content['storedAt'] = new \DateTime();
        unset($resource[sha1($url)]);
        $resource[sha1($url)] = $content;
        $this->updateResource($resourceName, $resource);
        $lock->release();
        $this->logger->info("unlocking category $resourceName", ["category" => $resourceName]);

    }

    public function retrieve($resourceName)
    {
        $lock = $this->lockFactory->createLock('file-' . $resourceName);
        $this->logger->info("locking category $resourceName", ["category" => $resourceName]);
        $lock->acquire(true);
        $content = $this->getResource($resourceName);

        $lock->release();
        $this->logger->info("unlocking category $resourceName", ["category" => $resourceName]);
        return $content;
    }

    private function getResource($resourceName)
    {
        $content = @file_get_contents($this->baseDir . "/" . $resourceName);
        $this->logger->debug("reading file", ["category" => $resourceName]);

        if ($content === null) {
            return [];
        }
        $finalContent = json_decode($content, true) ?? [];
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning(json_last_error_msg(), ["category" => $resourceName]);
        }

        return $finalContent;
    }

    private function updateResource($resourceName, $content)
    {
        $this->logger->info("saving update into $resourceName", ["category" => $resourceName]);
        return file_put_contents($this->baseDir . "/" . $resourceName, json_encode($content));
    }

    private function getDetails($url)
    {
        $options = stream_context_create(array(
            "ssl" => array(
                "verify_peer" => false,
                "verify_peer_name" => false,
            ),
        ));
        $html = file_get_contents($url, context: $options) ?? "";
        $doc = new DomDocument();
        @$doc->loadHTML($html);
        $nodes = $doc->getElementsByTagName('title');
        $title = $nodes?->item(0)?->nodeValue;
        if ($title === null) {
            $this->logger->notice("no title found for $url", ["url" => $url]);
            $title = $url;
        }
        $xpath = new DOMXPath($doc);
        $query = '//*/meta[starts-with(@property, \'og:\')]';
        $metas = $xpath->query($query);
        $rmeta = [];
        foreach ($metas as $meta) {
            $property = $meta->getAttribute('property');
            $content = $meta->getAttribute('content');
            $property = str_replace("og:", "", $property);
            $rmeta[$property] = $content;
        }
        $settings = ["page" => $title, ...$rmeta];

        $metas = $doc->getElementsByTagName('meta');
        for ($i = 0; $i < $metas->length; $i++) {
            $meta = $metas->item($i);
            $settings[$meta->getAttribute('name')] = $meta->getAttribute('content');
        }
        if (isset($settings['keywords'])) {
            $settings['keywords'] = explode(',', $settings['keywords']);
        } else {
            $this->logger->info("no keywords find on page", ["url" => $url]);
        }
        return $settings;
    }

    public function delete($resourceName, $fileId)
    {
        $this->logger->info("locking category $resourceName", ["category" => $resourceName]);
        $lock = $this->lockFactory->createLock('file-' . $resourceName);
        $lock->acquire(true);
        $content = $this->getResource($resourceName);
        unset($content[$fileId]);
        $this->logger->info("deleted url $fileId in $resourceName", ["category" => $resourceName]);

        $this->updateResource($resourceName, $content);
        $lock->release();
        $this->logger->info("unlocking category $resourceName", ["category" => $resourceName]);

        if (count($content) === 0) {
            $this->deleteFile($resourceName);
            $this->logger->notice("no more content in $resourceName, deleting it", ["category" => $resourceName]);

        }
    }

    private function deleteFile($resourceName)
    {
        $lock = $this->lockFactory->createLock('file-' . $resourceName);
        $lock->acquire(true);
        @unlink($this->baseDir . "/" . $resourceName);
        $lock->release();
    }

}