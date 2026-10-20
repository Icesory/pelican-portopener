<?php
// Run only inside the fresh development container; never against the live panel.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.name') !== 'PortOpener Development' || config('database.default') !== 'sqlite') {
    throw new RuntimeException('Refusing to seed an unexpected panel.');
}
foreach ([['dev-admin', true], ['dev-owner', false], ['dev-other', false]] as [$username, $admin]) {
    if (App\Models\User::where('username', $username)->exists()) {
        continue;
    }
    $password = bin2hex(random_bytes(16));
    $user = $app->make(App\Services\Users\UserCreationService::class)->handle([
        'username' => $username,
        'email' => $username . '@portopener.test',
        'password' => $password,
        'root_admin' => $admin,
    ]);
    file_put_contents('/pelican-data/dev-login.txt', $username . ' ' . $user->email . ' ' . $password . "\n", FILE_APPEND);
    chmod('/pelican-data/dev-login.txt', 0600);
}
echo "Synthetic development users ready; passwords stored privately in /pelican-data/dev-login.txt.\n";
