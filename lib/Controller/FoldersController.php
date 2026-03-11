<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\Db\TimelineRoot;
use OCA\Memories\Exceptions;
use OCA\Memories\Util;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\Files\Folder;

class FoldersController extends GenericApiController
{
    #[NoAdminRequired]
    #[PublicPage]
    public function sub(string $folder): Http\Response
    {
        return Util::guardEx(function () use ($folder) {
            $folder = Util::sanitizePath($folder);
            if (null === $folder) {
                throw Exceptions::BadRequest('Invalid parameter folder');
            }

            // Get the root folder (share root or user root)
            $root = $this->fs->getShareNode() ?? Util::getUserFolder();
            if (!$root instanceof Folder) {
                throw Exceptions::BadRequest('Root is not a folder');
            }

            // Get the inner folder
            try {
                $node = $root->get($folder);
            } catch (\OCP\Files\NotFoundException) {
                throw Exceptions::NotFound("Folder not found: {$folder}");
            }

            // Make sure we have a folder
            if (!$node instanceof Folder) {
                throw Exceptions::BadRequest('Path is not a folder');
            }

            // In a future NC release, getDirectoryListing() has directly a mimetype filter
            // See https://github.com/nextcloud/server/commit/9741f5f17d95418178c64a106624f8e525a59e75
            // This should be possible soon:
            // use OCP\Files\FileInfo;
            // $node->getDirectoryListing(FileInfo::MIMETYPE_FOLDER)
            $folders = [];
            foreach ($node->getDirectoryListing() as $item) {
                if ($item instanceof Folder) {
                    $folders[] = $item;
                }
            }

            // Sort by name
            usort($folders, static fn ($a, $b) => strnatcmp($a->getName(), $b->getName()));

            // Construct root for the base folder. This way we can reuse the
            // root by filtering out the subfolders we don't want.
            $root = new TimelineRoot();
            $this->fs->populateRoot($root);

            // Process to response type
            $list = array_map(function ($node) use ($root) {
                // Base changes permanently remove any mounts outside the
                // target folder, so we need to use a clone for each subfolder
                $root = clone $root;

                // Switch the cloned root to use only this folder
                $root->addFolder($node);
                $root->baseChange($node->getPath());

                return [
                    'fileid' => $node->getId(),
                    'name' => $node->getName(),
                    'previews' => $this->tq->getRootPreviews($root),
                ];
            }, $folders);

            return new Http\JSONResponse($list, Http::STATUS_OK);
        });
    }
}
