<?php

namespace PortOpener\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Http\Client\RequestException;

/** Authenticated Wings transport. Never log request bodies or exception messages containing bootstrap secrets. */
class Ipv4Wings
{
    private function client(Server $server)
    {
        return app(DaemonServerRepository::class)->setServer($server)->getHttpClient()
            ->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false]);
    }

    public function state(Server $server): string
    {
        return $this->client($server)->get('/api/servers/'.$server->uuid)->throw()->json('state') ?? 'unknown';
    }

    public function ensureCreated(Server $server): void
    {
        try {
            $this->client($server)->get('/api/servers/'.$server->uuid)->throw();
            return;
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 404) throw $exception;
        }
        // The UUID was committed before this request. A timeout can always be retried with that same UUID.
        try {
            $this->client($server)->post('/api/servers', ['uuid' => $server->uuid, 'start_on_completion' => false])->throw();
        } catch (RequestException $exception) {
            if ($exception->response->status() !== 409) throw $exception;
            $this->client($server)->get('/api/servers/'.$server->uuid)->throw();
        }
    }

    public function power(Server $server, string $action): void
    {
        $this->client($server)->post('/api/servers/'.$server->uuid.'/power', ['action' => $action])->throw();
    }

    public function reinstall(Server $server): void
    {
        $this->client($server)->post('/api/servers/'.$server->uuid.'/reinstall')->throw();
    }

    public function sync(Server $server): void
    {
        $this->client($server)->post('/api/servers/'.$server->uuid.'/sync')->throw();
    }

    public function files(Server $server, string $directory): array
    {
        try {
            $response = $this->client($server)->get('/api/servers/'.$server->uuid.'/files/list-directory', ['directory' => $directory]);
            return array_column($response->throw()->json(), 'name');
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) return [];
            throw $exception;
        }
    }

    public function bootstrap(Server $server, array $body): void
    {
        $base = '/api/servers/'.$server->uuid.'/files/';
        if (!in_array('data', $this->files($server, '/'), true)) {
            $this->client($server)->post($base.'create-directory', ['name' => 'data', 'path' => '/'])->throw();
        }
        $this->client($server)->post($base.'chmod', ['root' => '/', 'files' => [['file' => 'data', 'mode' => '700']]])->throw();
        $this->client($server)->withQueryParameters(['file' => '/data/bootstrap.json.pending'])
            ->withBody(json_encode($body, JSON_THROW_ON_ERROR), 'application/json')->post($base.'write')->throw();
        $this->client($server)->post($base.'chmod', ['root' => '/', 'files' => [['file' => 'data/bootstrap.json.pending', 'mode' => '600']]])->throw();
        // Wings refuses rename-over-existing. Only discard an obsolete bootstrap, never durable service data.
        if (in_array('bootstrap.json', $this->files($server, '/data'), true)) {
            $this->client($server)->post($base.'delete', ['root' => '/data', 'files' => ['bootstrap.json']])->throw();
        }
        $this->client($server)->put($base.'rename', ['root' => '/data', 'files' => [['from' => 'bootstrap.json.pending', 'to' => 'bootstrap.json']]])->throw();
    }
}
