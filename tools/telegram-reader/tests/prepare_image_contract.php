<?php

declare(strict_types=1);

// Isolated app_test only: approve synthetic albums and enqueue their photo jobs for cross-language HTTP testing.
require '/var/www/html/vendor/autoload.php';

$app = \App\Tests\Support\TestEnv::app();
$c = $app->container();
$db = $c->get(\App\Kernel\Database\Connection::class);
if ($db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
    throw new \RuntimeException('Test database required');
}
$workspaces = $c->get(\App\Domain\Workspace\WorkspaceRepository::class);
foreach ($db->table('source_items')->get() as $item) {
    $workspace = $workspaces->findById((int) $item['workspace_id']) ?? throw new \RuntimeException('Missing workspace');
    $member = $workspaces->membership($workspace->id, $workspace->ownerId) ?? throw new \RuntimeException('Missing member');
    $ctx = \App\Domain\Workspace\WorkspaceContext::from($workspace, $member);
    $row = $db->table('sources')->where('workspace_id', '=', $ctx->workspaceId)->where('id', '=', $item['source_id'])->first();
    $source = $c->get(\App\Domain\Source\SourceRepository::class)->find($ctx, (string) ($row['public_id'] ?? '')) ?? throw new \RuntimeException('Missing source');
    $c->get(\App\Domain\Source\Selection\SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
    $materials = $c->get(\App\Domain\Content\Processing\MaterialRepository::class);
    $revision = \App\Domain\Content\Processing\MaterialRepository::revision($item, $materials->messages($ctx, $source, (int) $item['id']));
    $c->get(\App\Domain\Content\ImageProcessing\ImageWorkflow::class)->request($ctx, $source, (string) $item['public_id'], $revision);
}
echo 'Synthetic approved image jobs ready', PHP_EOL;
