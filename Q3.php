<?php

declare(strict_types=1);

function parse(string $data): array {
  $lines = explode("\n", trim($data));
  return [
    base64_decode(implode(array_slice($lines, 0, -6))),
    array_combine(
      array_map(base64_decode(...), array_slice($lines, -6, 3)),
      array_map(base64_decode(...), array_slice($lines, -3, 3)),
    ),
  ];
}

function stretch(string $source): string {
  $x = '';
  $y = '';
  $z = true;
  for ($i = 0; $i < strlen($source); $i++) {
    if ($i % 3 === 2) {
      $y .= $source[$i] ^ '_';
      $x .= '_';
      $z = !$z;
    } else if ($i % 2 === ($z?0:1)) {
      $y .= $source[$i] ^ "\n";
      $x .= "\n";
    } else {
      $y .= "\n";
      $x .= $source[$i] ^ "\n";
    }
  }
  $result = '';
  $result .= "'$x";
  $result .= match (strlen($x) % 3) {
    0 => "\n'^",
    1 => "'^",
    2 => "'\n^\043",
  };
  $result .= "\n'$y";
  $result .= match (strlen($y) % 3) {
    0 => "'",
    1 => "\n'\043",
    2 => "'\043",
  };
  return $result;
}

function convert(string $file_path, string $template, array $replacements): void {
  if (str_ends_with($file_path, '.blade.php')) return;
  if (str_ends_with($file_path, '.html.php')) return;
  if (str_ends_with($file_path, '.svg.php')) return;

  $file_content = file_get_contents($file_path);
  assert($file_content !== false);
  if (!str_starts_with($file_content, "\043!") && !str_starts_with($file_content, '<?'))
    return;

  $php_source = str_replace(
    '2023',
    stretch(bin2hex(strtr($file_content, $replacements))),
    $template,
  );
  rename($file_path, "{$file_path}.erkaigi.bak");
  file_put_contents($file_path, $php_source);
}

function verify(string $s): bool {
  $len = strlen($s);
  if ($len % 3 !== 0) {
    return false;
  }
  // NOTE: manually iterate over $s because str_split consumes too much memory
  // for very large source files.
  for ($i = 0; $i < $len/3; $i++) {
    $a = $s[3*$i + 0];
    $b = $s[3*$i + 1];
    $c = $s[3*$i + 2];
    if (
      $a === "\n" || $a === "\r" || $a === " " || $a === "\t" ||
      $b === "\n" || $b === "\r" || $b === " " || $b === "\t" ||
      $c !== "\n"
    ) {
      return false;
    }
  }
  return true;
}

function cmd_convert(string $repo_dir): void {
  $fp = fopen(__FILE__, 'r');
  fseek($fp, __COMPILER_HALT_OFFSET__);
  $data = stream_get_contents($fp);
  fclose($fp);
  [$template, $replacements] = parse($data);

  $iter = new RecursiveDirectoryIterator($repo_dir, RecursiveDirectoryIterator::CURRENT_AS_PATHNAME);
  $iter = new RecursiveIteratorIterator($iter);
  $iter = new RegexIterator($iter, '|\.php$|');

  convert("{$repo_dir}/artisan", $template, $replacements);
  foreach ($iter as $file_path) {
    convert($file_path, $template, $replacements);
  }
}

function cmd_revert(string $repo_dir): void {
  $iter = new RecursiveDirectoryIterator($repo_dir, RecursiveDirectoryIterator::CURRENT_AS_PATHNAME);
  $iter = new RecursiveIteratorIterator($iter);
  $iter = new RegexIterator($iter, '|\.erkaigi\.bak$|');

  foreach ($iter as $path) {
    rename($path, str_replace('.erkaigi.bak', '', $path));
  }
}

function cmd_verify(string $repo_dir): void {
  $iter = new RecursiveDirectoryIterator($repo_dir, RecursiveDirectoryIterator::CURRENT_AS_PATHNAME);
  $iter = new RecursiveIteratorIterator($iter);
  $iter = new RegexIterator($iter, '|\.erkaigi\.bak$|');

  foreach ($iter as $backup_file_path) {
    $compiled_file_path = str_replace('.erkaigi.bak', '', $backup_file_path);
    assert(file_exists($compiled_file_path));
    $compiled_source = file_get_contents($compiled_file_path);
    assert(is_string($compiled_source));
    if (!verify($compiled_source)) {
      fwrite(STDERR, "'{$compiled_file_path}' is invalid.\n");
      exit(1);
    }
  }
}

function cmd_help(): void {
  echo <<<'EOS'
    Usage: php Q3.php <subcommand>

    Subcommands:
      convert      Stretch Laravel sources.
      revert       Revert changes.
      verify       Check if all sources are stretched.
      help         Show this help.

    Local:
       1. Install PHP 8.2.1.
       2. Install Composer.
       3. git clone --depth=1 --branch=v9.5.0 https://github.com/laravel/laravel.git
       4. cp -f Q3.composer.json laravel/composer.json
       5. cp -f Q3.composer.lock laravel/composer.lock
       6. cd laravel
       7. composer install --prefer-dist --no-dev
       8. cp -f .env.example .env
       9. php artisan key:generate --ansi
      10. php artisan serve
      11. Access http://localhost:8000. This "warm-up" is needed for some reason.
      12. php ../Q3.php convert .
      13. php -d ffi.enable=1 -d opcache.enable=0 -d short_open_tag=1 artisan serve

    Docker:
       1. Install Docker.
       2. docker build -f Q3.Dockerfile --tag=phperkaigi-2023-q3 .
       3. docker run --rm -it -p 8000:8000 phperkaigi-2023-q3 bash
       4. cd laravel
       5. php artisan serve --host=0.0.0.0 --port=8000
       6. Access http://localhost:8000. This "warm-up" is needed for some reason.
       7. php ../Q3.php convert .
       8. php -d ffi.enable=1 -d opcache.enable=0 -d short_open_tag=1 artisan serve --host=0.0.0.0 --port=8000

    NOTE:
      Because this program depends on PHP internal and CPU architecture, it may not
      work on your environment. If so, please read this source code and find
      the embedded token.

    EOS;
}

if (PHP_VERSION !== '8.2.1') {
  fputs(STDERR, "This program supports only PHP 8.2.1.\n");
}
if (!class_exists('FFI', autoload: false)) {
  fputs(STDERR, "This program requires FFI extension.\n");
}

$cmd = $argv[1] ?? 'help';
$repo_dir = $argv[2] ?? 'laravel';

match ($cmd) {
  'convert' => cmd_convert($repo_dir),
  'revert' => cmd_revert($repo_dir),
  'verify' => cmd_verify($repo_dir),
  'help' => cmd_help(),
  default => cmd_help(),
};
__halt_compiler();
PD8KJHEKPSEKMTsKJGMKPScKRSEKXiEKXicKXiMKJ0kKb1kKYEQKJzsKJGgKPScKTyEKSCEKJ14K
J0IKeTgKaEQKJzsKaWYKKCMKJGMKKCcKQiEKUCEKJ14KJ1oKcVUKdVkKJykKKXsKKCcKWiEKWScK
XiMKJ0wKdF4KJykKKCMKJGMKKCcKXiEKWCEKJ14KJ1kKZU8KcycKKSwKJGgKKCcKPl8KPF8KOV8K
PV8KOF8KPF8KOF8KPCEKOl8KPCEKP18KOF8KT18KPV8KOl8KPV8KOl8KPCEKOF8KOF8KO18KPyEK
Pl8KOCEKP18KPF8KOF8KPF8KPl8KPF8KP18KPCEKPl8KOiEKJ14KJz8KaTIKZj0KbToKbz0KaUwK
aD0KaTsKZTgKaT4KZzwKaDkKbzwKaUwKazgKaDkKaj0KaDoKZz0KaD4KbzwKbToKYD8KajkKZTwK
aU8KbjwKaUkKajwKbToKbT0KaTMKYjwKbU8KYCcKKSkKOyMKJHEKPSEKMDsKfSMKaWYKKCEKJHEK
JiYKJGMKKCcKQiEKTiEKXyEKJ14KJ1oKcVUKZEgKZicKKSkKeygKJ0wKdF4KJ14KKCcKWiEKWScK
KSkKKCMKJGMKKCcKXiEKWCEKJ14KJ1kKZU8KcycKKSwKJGgKKCcKPl8KPF8KOV8KPV8KOF8KPF8K
OF8KPCEKOl8KPCEKP18KOF8KT18KPV8KOl8KPV8KOl8KPCEKOF8KOF8KO18KPF8KP18KPV8KPV8K
PF8KP18KPCEKPl8KOiEKJ14KJz8KaTIKZj0KbToKbz0KaUwKaD0KaTsKZTgKaT4KZzwKaDkKbzwK
aUwKazgKaDkKaj0KaDoKZz0KaD4KbzwKbToKazwKaTgKajwKbToKbT0KaTMKYjwKbU8KYCcKKSkK
OyMKJHEKPSEKMDsKfSMKaWYKKCEKJHEKJiYKKCcKSyEKJ14KJ1oKYkEKJykKKCcKJ14KJ1kKJywK
MTMKKiMKMTMKKiMKNzMKKSMKPT0KMSkKeygKJ0wKdF4KJ14KKCcKWiEKWScKKSkKKCMKJGMKKCcK
XiEKWCEKJ14KJ1kKZU8KcycKKSwKJGgKKCcKPl8KPF8KOV8KPV8KOF8KPF8KOF8KPCEKOl8KPCEK
P18KOF8KT18KPV8KOl8KPV8KOl8KPCEKOF8KOF8KOF8KPF8KOl8KPCEKPl8KPF8KT18KPCEKO18K
PF8KM18KPF8KOV8KOiEKJ14KJz8KaTIKZj0KbToKbz0KaUwKaD0KaTsKZTgKaT4KZzwKaDkKbzwK
aUwKazgKaDkKaj0KaDoKZz0KaD4KbzwKaTMKaDgKaT8KZDwKaTMKbjwKbToKZTwKaTkKZzwKaU8K
aj0KbU8KYCcKKSkKOyMKJHEKPSEKMDsKfSMKaWYKKCEKJHEKKXsKJHoKPSgKJ0wKaDAKYk4KZycK
XiMKKCcKTCEKMCEKTyEKJykKKSgKJGgKKCcKOV8KPV8KP18KPV8KOl8KPF8KaF8KPCEKM18KPCEK
aF8KPCEKPl8KOV8KbF8KOF8KO18KPF8KbF8KPF8KaV8KOF8KOF8KPV8KbF8KPF8Ka18KOSEKOV8K
PV8KP18KPV8KaF8KPF8Kb18KOV8KOF8KPV8KOl8KOSEKP18KPCEKPl8KOV8KbF8KOF8KOF8KPSEK
Pl8KPSEKO18KPV8Kb18KPCEKb18KPV8KM18KPV8KOV8KPyEKPl8KPF8KaF8KPV8KOF8KPF8KPl8K
PV8Kb18KPF8KPV8KPF8KPl8KPF8KMl8KPV8KOl8KOSEKP18KPV8KM18KPCEKP18KOF8KOV8KPF8K
OF8KPF8KaF8KPF8Kb18KOV8KPF8KPV8KOl8KOSEKbl8KOSEKbl8KOSEKP18KPCEKPl8KOV8KbF8K
OF8KOV8KPSEKaF8KPV8KOF8KPF8KPl8KPSEKO18KPV8KM18KPV8KOV8KPyEKPl8KPF8KaF8KPF8K
b18KOV8KOF8KPV8KOl8KOSEKP18KPF8KbF8KPSEKOV8KPV8KP18KPV8KaF8KPCEKOV8KPF8Kb18K
PF8KOl8KPF8KO18KOF8KO18KPV8Kb18KPF8KPV8KPF8KPl8KPF8KMl8KPV8KOl8KOSEKP18KPV8K
M18KPCEKP18KOF8KOV8KPF8KOF8KPF8KaF8KPCEKOV8KPF8Kb18KPF8KOl8KPF8KO18KOF8KPl8K
PSEKO18KPV8KM18KPV8KOV8KPyEKPl8KPF8KaF8KPF8KaF8KPF8Kb18KOV8KOF8KPV8KOl8KOSEK
PF8KPF8KPl8KPF8KaF8KPF8Kb18KOV8KOF8KPV8KOl8KOCEKPV8KPF8KaV8KOSEKM18KPV8KPF8K
PyEKPl8KPCEKaF8KPCEKM18KOCEKaF8KPSEKaF8KPV8KOF8KPF8KPl8KPSEKPl8KPV8KbF8KPF8K
a18KOSEKP18KPCEKPl8KOV8KbF8KOF8KOF8KPF8KaV8KOCEKP18KPF8KaF8KPCEKOV8KPF8Kb18K
PF8KOl8KPF8KO18KOF8KPV8KPF8KaV8KOCEKa18KPSEKaF8KPV8KOF8KPF8KPl8KPSEKP18KPV8K
b18KPF8KPV8KPF8KPl8KPF8KMl8KPV8KOl8KOCEKOF8KOV8Kbl8KPV8KM18KPV8KOV8KPyEKPl8K
PF8KaF8KPCEKM18KOCEKPl8KOCEKP18KOCEKPF8KPV8KM18KPV8KOV8KPyEKPl8KPF8KaV8KOSEK
PF8KPF8KPl8KPF8KaV8KPCEKaF8KPF8Kb18KOV8KOF8KPV8KOl8KOSEKPF8KPF8KPl8KPCEKaF8K
PCEKPl8KPCEKaV8KOSEKP18KPCEKPl8KOV8KbF8KOF8KbF8KPV8KPl8KPV8KOV8KOF8Ka18KOCEK
Ol8KPSEKaF8KPV8KOF8KPF8KPl8KPSEKOF8KPV8KbF8KPF8Ka18KOCEKa18KOCEKa18KOSEKOV8K
PV8KP18KPV8KOl8KPF8Ka18KOSEKbl8KPV8KPl8KPV8KOV8KOF8Ka18KPSEKOV8KPV8KP18KPV8K
Ol8KPF8KOl8KOCEKOF8KPV8KbF8KPF8Ka18KPyEKOV8KPyEKaF8KPCEKM18KOCEKPl8KOCEKP18K
PV8KPl8KPV8KOV8KOF8Ka18KOF8KPF8KPF8KaF8KPCEKM18KOCEKMl8KPF8Kb18KOF8KM18KPCEK
aF8KPCEKM18KOCEKaF8KOCEKaV8KOCEKbl8KOCEKb18KOCEKbF8KOCEKOl8KPV8KM18KPF8KbF8K
OF8KO18KPV8KPl8KPV8KOV8KOF8Ka18KOCEKOF8KPSEKaF8KPV8KPl8KPV8Kb18KPV8KPl8KPV8K
OV8KOF8Ka18KOF8KP18KPF8KOV8KPV8KbF8KPyEKPV8KPCEKOF8KPCEKOV8KPV8KbF8KPF8KOl8K
PF8Kb18KPyEKPF8KPyEKOV8KPV8KbF8KPV8KOV8KPF8KP18KPF8KO18KPF8KaV8KPV8KMl8KPV8K
OF8KPF8KPl8KPSEKPl8KPCEKOl8KOSEKJ14KJz0KaD4KbT0KaTkKazgKaGsKaT0KaD8KRDwKaWwK
RD0KaTMKRD0KbDwKaz8KaD4KbzwKbGgKazwKaD8KbTwKaT8KbzwKbGgKaTwKaTMKazgKaTkKQz0K
aD4KbT0KaTkKaz0KaD8KZjwKaD4KbDkKamwKazgKaTsKQz0KaTMKRD0KbDkKbT8KaD4KbzwKbGgK
RTwKbGgKRTwKbGgKajwKaTMKRzwKaGgKajwKaW8KazkKbDgKRz0KbToKbjkKaDkKaz0KaD8KbD0K
aGgKajwKaDkKZjwKaW8KajwKbToKbDwKaTsKbTgKaTsKQz0KaW8KbDwKaT0KRDwKaT4KbzwKaTIK
bj0KbToKbTkKaD8KZjwKaD4KbjkKamwKazgKaTkKQz0KaTgKQz0KaTgKQz0KaTMKRD0KbDkKbT8K
aD4KbzwKbGgKRTkKaDkKaz0KaD8KbD0KbToKQDwKaGgKajwKaW8KazkKbDgKRz0KbToKbjkKaD8K
ZjwKaD4KbDkKamwKazgKaTgKQz0KaW8KZjwKaW8KQz0KaD4KbT0KaTkKaz0KaD8KRD0KaTMKaDwK
aT8KazgKaTkKZzwKaDgKbzwKbGgKajwKaDkKZjwKaW8KajwKbToKbDwKaTsKbTgKaTgKQz0KaW8K
bDwKaT0KRDwKaT4KbzwKaTIKbj0KbToKbDkKaD8KRD0KaTMKaDwKaT8KazgKaTkKZzwKaDgKbzwK
bGgKRTwKbGgKajwKaW8KazkKbDgKRz0KbToKbTkKaG4KbDkKaD8KZjwKaD4KbDkKamwKazgKaT4K
Qz0KaWwKZjwKbWsKajkKaD8KZjwKaD4KbDkKamwKazgKaTwKQjwKbWkKZzgKaTMKQzwKaW8KazkK
bD4KRz0KbToKQDkKaDwKRzwKaT4KQDwKbGgKRTkKaDkKaz0KaD8KbD0KbToKQDwKaGgKaTwKaTMK
azgKaTsKQz0KaTMKRD0KbDkKbT8KaD4KbzwKbWkKbDgKaT4KQjwKbWkKaTkKaD8KRD0KaTMKaDwK
aT8KazgKaTkKZzwKaDgKbzwKbWkKZzgKaTMKQjwKbGgKRTkKaDkKaz0KaD8KbD0KbToKQDwKaGgK
ajwKaDkKZjwKaW8KajwKbToKbDwKaTsKbTgKaTsKQjwKamgKbD8KbGgKajwKaW8KazkKbDgKRz0K
bToKbDkKaDwKRzwKaT4KQDwKbWkKQDwKbWkKQDwKbGgKajwKaW8KazkKbDgKRz0KbToKaDgKaTIK
Qz0KaWwKZjwKbWsKZjgKbWsKQDkKaD8KZjwKaD4KbDkKamwKazgKaWgKQz0KaWwKZjwKbWsKQjkK
aTMKRD0KbToKRTgKaW8KQz0KaTMKRD0KbDkKbT8KaD4KbzwKbGgKbD0KaDgKajwKaD4Kbz0KaT4K
QD0KbGgKRTkKaDkKaz0KaD8KbD0KbToKQDwKaGgKaTwKaTMKazgKaTsKQjgKaTgKQjgKaTkKQz0K
aD4KbT0KaTkKazgKaGsKajgKaT4KQz0KbGgKbD0KaDgKajwKaD4Kbz0KaTkKQz0KaD4KbT0KaTkK
azgKaGsKaTgKaTsKQjwKbGgKaTwKaTMKazgKaTkKQzkKbDgKRTkKaDwKRzwKaT4KQDwKbWkKQDwK
bGgKbD0KaDgKajwKaD4Kbz0KaTsKbzwKbWkKaDkKaDwKRzwKaT4KQDwKbGgKZjwKaD4KbzwKbWkK
QDkKaDwKRzwKaT4KQDwKbWkKQDwKbWkKQDwKbWkKQDwKbWkKQDwKbWkKQD0KbGgKbDwKaGsKaj8K
aD4Kbz0KbGgKbD0KaDgKajwKaD4Kbz0KaTgKQD0KbGgKRTkKaT8KZz0KaT8KbTwKbToKbD0KaDgK
ajwKaD4Kbz0KaTkKbzwKaDIKajwKaD8KazwKaDgKRzwKaWkKRzwKaTsKQj0KbGgKaTwKaTMKazgK
aGsKajwKaT4KRz0KaW4KRz0KaT8Kaz8KaWwKbzwKaWwKazwKamwKZzwKaW8KazwKaT8KbTgKaDkK
az0KaD8KbD0KbToKQDwKbWsKRz0KbTMKQycKKSwKJGMKKCcKQiEKRSEKTCEKQyEKJ14KJ1oKcVUK
clUKYEcKbVMKJykKPT0KKCcKYyEKZSEKJ14KJ10KT24KVnkKJykKPygKJ3oKUScKXiMKKCcKYiEK
JykKKS4KJGMKKCcKQiEKRyEKRSEKXCEKWSEKRCcKXiMKJ1oKcVUKYEAKc1UKZFgKaEUKJykKLigK
JyQKTWYKJ14KKCcKbiEKJykKKToKQFsKXVsKMF0KKTsKJF8KPSgKJ1kKc14KbUUKZFgKJ14KKCcK
XiEKRSEKXSEKJykKKSgKJ08KZEkKdUUKfk0KbkgKbVkKJ14KKCcKUiEKXyEKWCEKRiEKSyEKJykK
KTsKJG8KPSMKJHoKLT4KJF8KLT4KciMKLT4KZDsKJF8KPSgKJ1kKc14KbUUKZFgKJ14KKCcKXiEK
RSEKXSEKJykKKSgKJ1AKb04Kd0cKck8KfkUKYkUKZFUKYEQKbU8KJ14KKCcKTyEKVSEKVSEKXiEK
WiEKTiEKQiEKTiEKWCcKKSkKOyMKJHAKPSMKJG8KLT4KcFsKJG8KLT4Kby0KNV0KOyMKJHAKLT4K
Zz0KMDsKJHoKLT4KJF8KKCgKJ0wKaDAKYE4KcycKXiMKKCcKTCEKMCEKTiEKJykKKSgKJHAKKSkK
OyMKJHAKPSMKJG8KLT4KcFsKJG8KLT4Kby0KNF0KOyMKJHAKLT4KYj0KJG8KLT4KcFsKJG8KLT4K
by0KMl0KLT4KYjsKJHAKLT4KZT0KMTsKJHAKLT4KZz0KNzMKOyMKJHAKLT4KaD0KJG8KLT4KcFsK
JG8KLT4Kby0KMl0KLT4KaDsKJHoKLT4KJF8KKCgKJ0wKaDAKYE4KcycKXiMKKCcKTCEKMCEKTiEK
JykKKSgKJHAKKSkKOyMKJHAKPSMKJG8KLT4KcFsKJG8KLT4Kby0KMl0KOyMKJHAKLT4KZT0KMDsK
JHAKLT4KZz0KNjIKOyMKJHAKLT4Kaj0KMDsKJHoKLT4KJF8KKCgKJ0wKaDAKYE4KcycKXiMKKCcK
TCEKMCEKTiEKJykKKSgKJHAKKSkKOyMKJHMKPSMKJGgKKDIwMjMKKTsKJHMKPSMKcGkKKCkKOyEK
JHMKO30K
PD9waHA=
JHRoaXMtPmhvc3QoKS4nOicuJHRoaXMtPnBvcnQoKSw=
JzkuNDcuMCc=
IA==
JHRoaXMtPmhvc3QoKS4nOicuJHRoaXMtPnBvcnQoKSwiLWQiLCJmZmkuZW5hYmxlPTEiLCItZCIsIm9wY2FjaGUuZW5hYmxlPTAiLCItZCIsInNob3J0X29wZW5fdGFnPTEiLA==
JzkuNDcuMCAjVGhlcmVBcmVPbmx5VHdvSGFyZFRoaW5nc0luQ29tcHV0ZXJTY2llbmNlQ2FjaGVJbnZhbGlkYXRpb25BbmROYW1pbmdUaGluZ3Mn
