<?php
declare(strict_types=1);

namespace Webcam\Service\Storage;

use Throwable;
use Webcam\App;
use Webcam\Domain\Camera;
use Webcam\Infrastructure\Storage\CameraDataStore;

/** Liest Top-Likes aus der Kamera-DB und puffert nur deren unveränderte Rohzeilen. */
final class BestOfLikeRepository
{
    public function __construct(
        private readonly App $app,
        private readonly BestOfGalleryCache $cache
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function topLiked(Camera $camera, int $limit): array
    {
        $limit = max(1, $limit);
        $rows = $this->cache->loadTopLiked($camera, $limit);
        if ($rows !== null) {
            return $rows;
        }
        try {
            $rows = (new CameraDataStore($this->app, $camera, null, true))->topLiked($limit);
        } catch (Throwable) {
            return [];
        }
        $this->cache->writeTopLiked($camera, $limit, $rows);
        return $rows;
    }
}
