<?php

// Cliente HTTP de loopback: cookies solo en memoria; nunca imprimir cuerpos/tokens.
class BootstrapCheckHttp
{
    private \CurlHandle $curl;

    public function __construct()
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => '', CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5]);
    }

    public function request(string $method, string $path, array $data = [], ?string $token = null, bool $json = true): array
    {
        if (!str_starts_with($path, '/') || str_contains($path, '://')) throw new RuntimeException('URL no autorizada');
        $headers = ['Accept: '.($json ? 'application/json' : 'text/html')];
        if ($token !== null) $headers[] = 'X-CSRF-TOKEN: '.$token;
        curl_setopt($this->curl, CURLOPT_URL, 'http://127.0.0.1:8766'.$path);
        if ($method === 'GET') {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($this->curl);
        if ($body === false) throw new RuntimeException('Fallo HTTP de loopback');
        return ['status' => curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), 'body' => $body,
            'redirect' => curl_getinfo($this->curl, CURLINFO_REDIRECT_URL), 'json' => json_decode($body, true)];
    }

    public function login(string $email, string $password): void
    {
        $page = $this->request('GET', '/login', [], null, false);
        bootstrap_check_assert($page['status'] === 200 && preg_match('/name="_token"\s+value="([^"]+)"/', $page['body'], $match) === 1, 'login form and CSRF token');
        $result = $this->request('POST', '/login', ['email' => $email, 'password' => $password, '_token' => html_entity_decode($match[1])], null, false);
        bootstrap_check_assert($result['status'] === 302 && !str_ends_with($result['redirect'], '/login'), 'real login redirects to authorized page');
    }

    public function token(): string
    {
        $page = $this->request('GET', '/agenda', [], null, false);
        bootstrap_check_assert($page['status'] === 200 && preg_match('/name="csrf-token"\s+content="([^"]+)"/', $page['body'], $match) === 1, 'authenticated agenda and session token');
        return html_entity_decode($match[1]);
    }
}

function bootstrap_check_functional(\Illuminate\Foundation\Application $app, \App\Models\Usuario $owner): void
{
    $output = new \Symfony\Component\Console\Output\ConsoleOutput();
    $input = new \Symfony\Component\Console\Input\ArgvInput();
    $question = new \Symfony\Component\Console\Question\Question('Reintroduce la contraseña DEL ENSAYO para comprobar el login HTTP real: ');
    $question->setHidden(true)->setHiddenFallback(false);
    $password = (new \Symfony\Component\Console\Helper\QuestionHelper())->ask($input, $output, $question);
    bootstrap_check_assert(is_string($password) && \Illuminate\Support\Facades\Hash::check($password, $owner->password), 'owner login credential matches stored hash');
    $client = new BootstrapCheckHttp();
    $client->login($owner->email, $password);
    unset($password);
    $token = $client->token();
    bootstrap_check_assert($client->request('GET', '/api/clients')['status'] === 200, 'owner session persists on API');
    $anonymous = new BootstrapCheckHttp();
    bootstrap_check_assert($anonymous->request('GET', '/api/clients')['status'] === 401, 'anonymous clients denied');
    bootstrap_check_assert($anonymous->request('GET', '/api/appointments')['status'] === 401, 'anonymous agenda API denied');
    $day = now(config('app.timezone'))->addDay()->toDateString();
    $style = \App\Models\Usuario::where('role', 'estilista')->sole();
    // Catálogo de ensayo adicional, no producto gemelo ni stock inventado.
    $marker = dirname(__DIR__, 2).'/storage/app/private/bootstrap-check-functional-started.json';
    if (is_file($marker)) throw new RuntimeException('Ensayo funcional ya iniciado; revisar antes de repetir escrituras');
    $handle = fopen($marker, 'x');
    if (!$handle) throw new RuntimeException('No se pudo reservar el ensayo');
    fwrite($handle, json_encode(['started_at' => gmdate('c')]));
    fclose($handle);
    $service = \App\Models\Servicio::create(['name' => 'Servicio exclusivo H13-C', 'price' => 200, 'duration' => 45, 'is_active' => true]);
    bootstrap_check_assert(!\App\Models\Articulo::whereKey($service->id)->exists(), 'service exists only in services');
    $newClient = $client->request('POST', '/api/clients', ['name' => 'Cliente ensayo H13-C'], $token);
    bootstrap_check_assert($newClient['status'] === 200, 'authorized client creation');
    $customer = \App\Models\Cliente::where('name', 'Cliente ensayo H13-C')->sole();
    $payload = ['client_id' => $customer->id, 'service_id' => $service->id, 'stylists' => [$style->id], 'appointment_date' => $day.'T10:00', 'duration_minutes' => 45];
    $anonLogin = $anonymous->request('GET', '/login', [], null, false);
    bootstrap_check_assert(preg_match('/name="_token"\s+value="([^"]+)"/', $anonLogin['body'], $match) === 1, 'anonymous session token available');
    bootstrap_check_assert($anonymous->request('POST', '/api/appointments', $payload, html_entity_decode($match[1]))['status'] === 401, 'anonymous mutation denied even with valid CSRF');
    bootstrap_check_assert($client->request('POST', '/api/appointments', $payload)['status'] === 419, 'missing CSRF rejected by real middleware');
    bootstrap_check_assert($client->request('POST', '/api/appointments', $payload, 'incorrect-token')['status'] === 419, 'invalid CSRF rejected');
    bootstrap_check_assert(\App\Models\Cita::count() === 0, 'rejected requests wrote no appointments');
    $first = $client->request('POST', '/api/appointments', $payload, $token);
    bootstrap_check_assert($first['status'] === 201 && is_int($first['json']['appointment']['id'] ?? null), 'appointment created with real ID');
    $payload['appointment_date'] = $day.'T12:00';
    $second = $client->request('POST', '/api/appointments', $payload, $token);
    bootstrap_check_assert($second['status'] === 201 && ($second['json']['appointment']['appointment_date'] ?? '') === $day.' 12:00:00', 'same stylist second appointment; saved local date/time');
    $selected = $client->request('GET', '/api/appointments?fecha='.$day);
    bootstrap_check_assert($selected['status'] === 200 && count($selected['json']) === 2, 'selected day lists both appointments');
    $today = $client->request('GET', '/api/appointments');
    bootstrap_check_assert($today['status'] === 200 && count($today['json']) === 0, 'default today excludes future appointments');
    $agenda = $client->request('GET', '/agenda?fecha='.$day, [], null, false);
    bootstrap_check_assert($agenda['status'] === 200 && str_contains($agenda['body'], \Carbon\Carbon::parse($day)->format('d/m/Y')), 'HTML agenda selected date');
    bootstrap_check_assert($client->request('GET', '/api/appointments?fecha=2026-02-30')['status'] === 422, 'invalid date rejected');
    $account = $app['db']->table('cuentas_contables')->where('codigo', '3.3')->first();
    bootstrap_check_assert($client->request('DELETE', '/catalogo/'.$account->id, [], $token)['status'] === 403, 'protected base account cannot be deleted');
    $oldHash = $style->password;
    $trialPassword = bin2hex(random_bytes(24));
    try {
        $style->password = $trialPassword;
        $style->save();
        $employeeClient = new BootstrapCheckHttp();
        $employeeClient->login($style->email, $trialPassword);
        bootstrap_check_assert($employeeClient->request('GET', '/agenda', [], null, false)['status'] === 200, 'stylist agenda allowed');
        bootstrap_check_assert($employeeClient->request('GET', '/catalogo')['status'] === 403, 'stylist accounting access denied');
    } finally {
        $style->password = $oldHash;
        $style->save();
        unset($trialPassword, $oldHash);
    }
    bootstrap_check_clean_business($app);
    bootstrap_check_record('functional', ['passed' => true, 'date' => $day,
        'appointment_ids' => [$first['json']['appointment']['id'], $second['json']['appointment']['id']],
        'client_id' => $customer->id, 'service_id' => $service->id,
        'financial_operations' => 0, 'http_login_session_csrf_roles' => true]);
    $output->writeln('Ensayo terminado. Puedes cerrar esta ventana. No se modificaron bases originales.');
}
