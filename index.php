<?php

/**
 * https://api.opensuse.org/apidocs/
 */
const API_BASE = 'https://api.opensuse.org';

function devel_info($package, $project = 'openSUSE:Factory') {
  // https://api.opensuse.org/source/openSUSE:Factory/Mesa/_meta
  if ($xml = xml_fetch("source/$project/$package/_meta")) {
    if (isset($xml->devel)) {
      return current($xml->devel->attributes());
    }
  }
  return false;
}

function binary_version($binary, $package, $project = 'openSUSE:Factory', $repository = 'standard', $arch = 'x86_64') {
  if ($list = binary_list($package, $project, $repository, $arch)) {
    foreach ($list->binary as $entry) {
      if (starts_with($entry['filename'], $binary)) {
        return current($entry) + [
          'binary' => $binary,
          'version' => current(explode('-', substr($entry['filename'], strlen($binary) + 1), 2)),
        ];
      }
    }
  }
  return false;
}

function binary_list($package, $project = 'openSUSE:Factory', $repository = 'standard', $arch = 'x86_64') {
  // https://api.opensuse.org/build/openSUSE:Factory/standard/x86_64/Mesa
  if ($xml = xml_fetch("build/$project/$repository/$arch/$package")) {
    return $xml;
  }
  return false;
}

function xml_fetch($path, $url_base = API_BASE, $html = false) {
  static $credentials;

  if (!isset($credentials)) {
    $credentials = base64_encode(trim(file_get_contents('credentials')));
  }

  $options = [
    'http' => [
      'method' => 'GET',
      'header' => 'Authorization: Basic ' . $credentials,
    ]
  ];
  if ($html) unset($options['header']);
  $context = stream_context_create($options);

  $url = "$url_base/$path";
  if (($contents = xml_cache_get($url)) === false) {
    $contents = file_get_contents($url, false, $context);
    xml_cache_set($url, $contents);
  }

  if ($contents) {
    if ($html) {
      $document = @DOMDocument::loadHTML($contents);
      if ($document) {
        return simplexml_import_dom($document);
      }
    } else {
      if ($xml = simplexml_load_string($contents)) {
        return $xml;
      }
    }
  }

  return false;
}

function xml_cache_set($url, $contents) {
  return file_put_contents('cache/' . sha1($url) . '.xml', $contents);
}

const CACHE_LIFE = 3600;

function xml_cache_get($url) {
  static $expired;

  if (!isset($expired)) {
    if (!file_exists('cache/expires')) {
      $expired = true;
    }
    else {
      $expired = (time() - (int) file_get_contents('cache/expires')) >= 0;
    }

    if ($expired) {
      if (!is_dir('cache')) {
        mkdir('cache');
      }
      file_put_contents('cache/expires', time() + CACHE_LIFE);
    }
  }

  $path = 'cache/' . sha1($url) . '.xml';
  if ($expired || !file_exists($path)) return false;
  if (file_exists($path)) return file_get_contents($path);
}

function starts_with($haystack, $needle) {
  // search backwards starting from haystack length characters from the end
  return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
}

const DOWNLOAD_URL_PREFIX = 'http://download.opensuse.org/repositories/';

$packages = parse_ini();
function parse_ini($file = 'package.ini', $default_devel_repo = 'openSUSE_Factory') {
  $config = parse_ini_file($file, true);
  foreach ($config as $group => &$entry) {
    $entry = $entry['package'];
    foreach ($entry as &$package) {
      $devel_repo = $default_devel_repo;
      $parts = explode('@', $package, 2);
      if (count($parts) == 2) {
        $package = $parts[0];
        $devel_repo = $parts[1];
      }
      $parts = explode('/', $package, 2);
      if (count($parts) == 1) {
        // Binary and package are the same so duplicate entry.
        $parts[] = current($parts);
      }
      $package = [
        'package' => $parts[0],
        'binary' => $parts[1],
        'devel_repo' => $devel_repo,
      ];
    }
  }
  return $config;
}

$html = print_packages($packages, rpm_list());
function print_packages(array $packages, $tumbleweed) {
  $html = '';
  foreach ($packages as $group => $list) {
    if ($group != 'Base') {
      $html .= "<tr><th colspan=\"5\">$group</th></tr>\n";
    }
    foreach ($list as $package) {
      if ($devel = devel_info($package['package'])) {
        $devel_version = binary_version($package['binary'], $devel['package'], $devel['project'], $package['devel_repo']);
      }
      else {
        unset($devel_version);
      }

      $snapshot_version = binary_version($package['binary'], $package['package'], 'openSUSE:Factory', 'snapshot');
      $factory_version = binary_version($package['binary'], $package['package'], 'openSUSE:Factory', 'standard');

      $package = $package['binary'];
      $updated = $tumbleweed[$package]['version'] != $factory_version['version'];
      $html .= "<tr title=\"Tumbleweed: {$tumbleweed[$package]['date']}\nFactory: {$factory_version['mtime']}\"" .
        ($updated ? ' class="updated"' : '') . ">\n" .
        "<td>$package</td>\n" .
        "<td>{$tumbleweed[$package]['version']}</td>\n" .
        "<td>" . (isset($snapshot_version) ? $snapshot_version['version'] : '') . "</td>\n" .
        "<td>{$factory_version['version']}</td>\n" .
        "<td>" . (isset($devel_version) ? $devel_version['version'] : '') . "</td>\n" .
        "</tr>\n";
    }
  }
  return $html;
}

function rpm_list() {
  $info = [];
  foreach (['oss' => 'x86_64', 'src-oss' => 'src'] as $repo => $arch) {
    if ($xml = xml_fetch("tumbleweed/repo/$repo/suse/$arch", 'http://download.opensuse.org', true)) {
      foreach ($xml->xpath('//a[contains(@href, ".rpm") and not(contains(@href, ".mirrorlist"))]') as $link) {
        if (preg_match('/^(.*)-([\d_.]+)-.*\.rpm$/', (string) $link['href'], $match)) {
          $info[$match[1]] = [
            'version' => $match[2],
            'date' => 'lol',
          ];
        }
      }
    }
  }
  return $info;
}

?>
<html>
  <head>
    <title>openSUSE Tumbleweed</title>
    <link rel='stylesheet prefetch' href='http://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css'>
    <link rel="stylesheet" href="style.css">
  </head>
  <body>
    <div id="tumbleweed">
      <a href="https://en.opensuse.org/Portal:Tumbleweed">
        <img src="https://en.opensuse.org/images/c/c1/Tumbleweed.png" alt="openSUSE Tumbleweed">
      </a>
    </div>
    <div id="versions">
      <h3>Latest <a href="https://build.opensuse.org/project/show/openSUSE:Factory">Factory</a> to <a href="https://openqa.opensuse.org/group_overview/1">Next Tumbleweed</a></h3>
      <table>
        <thead>
          <tr>
            <th>Package</th>
            <th>Tumbleweed</th>
            <th>Snapshot</th>
            <th>Factory</th>
            <th>Devel</th>
          </tr>
        </thead>
        <tbody>
          <?php print $html; ?>
        </tbody>
      </table>
    </div>
  </body>
</html>
