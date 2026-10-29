<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use CommunitySDKs\Client;

$client = Client::fromFile(dirname(__DIR__).'/specs/jsonplaceholder.openapi.json');

// PHP named parameters work dynamically through __call().
$post = $client->getPost(id: 1);
var_dump($post->status, $post->data);

// Explicit API discovery works without generated code.
foreach ($client->operations()->all(method: 'GET') as $id => $operation) {
    echo "{$id}: {$operation['_path']}\n";
}
