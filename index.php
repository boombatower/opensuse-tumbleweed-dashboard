<?php

/**
 * https://api.opensuse.org/apidocs/
 */
const API_BASE = 'https://api.opensuse.org';

/**
 * Proper regex for parsing package version from full rpm filename.
 *
 * Previous substr approach worked fine until llvm decided to add 3_8 to
 * package name. The following regex extracts the full version number:
 *
 * /([^-]+)-[^-]+\.[^\.]+\.rpm$/
 *
 * In order to support gcc6 package the regex was altered to strip the
 * overly long +r123456 part to keep dashboard from overflowing.
 */
const RPM_VERSION_REGEX = '/^{binary}-([^-+]+?)(?:[+~][^-]+)?-[^-]+\.[^\.]+\.rpm$/';

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
      if ($package_info = rpm_version_extract($binary, $entry['filename'])) {
        return current($entry) + $package_info;
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
  // Only store self://packages on production.
  if ($_SERVER['HTTP_HOST'] != 'tumbleweed.boombatower.com' || $url == 'self://packages')
    return file_put_contents('cache/' . sha1($url) . '.xml', $contents);
}

const CACHE_LIFE = 3600;

function xml_cache_get($url) {
  global $ago;
  static $expired;


  if (!isset($expired)) {
    if (!empty($_GET['refresh']) || !file_exists('cache/expires')) {
      $expired = true;
    }
    else {
      $expired = ($left = time() - (int) file_get_contents('cache/expires')) >= 0;
      $ago = CACHE_LIFE + $left;
    }

    if ($expired) {
      if (!is_dir('cache')) {
        mkdir('cache');
      }
      file_put_contents('cache/expires', time() + CACHE_LIFE);
      $ago = 0;
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

if (!empty($_GET['rebuild']) || !($html = xml_cache_get('self://packages'))) {
  $packages = parse_ini();
  $html = print_packages($packages, rpm_list($packages));
  xml_cache_set('self://packages', $html);
}

function print_packages(array $packages, $tumbleweed) {
  $html = '';
  foreach ($packages as $group => $list) {
    if ($group != 'Base') {
      $html .= "<tr><th colspan=\"5\">$group</th></tr>\n";
    }
    foreach ($list as $package) {
      $tumbleweed_version = $tumbleweed[$package['binary']];
      $snapshot_version = binary_version($package['binary'], $package['package'], 'openSUSE:Factory', 'snapshot');
      $factory_version = binary_version($package['binary'], $package['package'], 'openSUSE:Factory', 'standard');
      if ($devel = devel_info($package['package'])) {
        $devel_version = binary_version($package['binary'], $devel['package'], $devel['project'], $package['devel_repo']);
      }
      else {
        unset($devel_version);
      }

      $cells = [];
      $diff_count = -1;
      $previous = false;
      foreach (array_reverse(['tumbleweed', 'snapshot', 'factory', 'devel']) as $version) {
        $version = isset(${$version . '_version'}['version']) ? ${$version . '_version'}['version'] : '';
        $changed = false;
        if ($version != $previous) {
          $changed = true;
          $diff_count++;
        }
        $cells[] = '<td class="version-' . $diff_count . ($changed ? ' changed' : '') . '">' .
          $version .
          '</td>';
        $previous = $version;
      }

      $updated = $tumbleweed_version['version'] != $factory_version['version'];
      $html .= '<tr' .
        ($updated ? ' title="Newer version present in Factory" class="update-inbound"' : '') . ">\n" .
        '<td><a href="' . package_url('openSUSE:Factory', $package['package']) . '">' .
        $package['binary'] .
        "</a></td>\n" .
        implode("\n", array_reverse($cells)) .
        "</tr>\n";
    }
  }
  return $html;
}

function package_url($project, $package, $op = 'show', $repo = 'openSUSE_Factory') {
  // https://build.opensuse.org/package/show/X11:XOrg/Mesa
  return "https://build.opensuse.org/package/$op/$project/$package" .
    ($op == 'binaries' ? '?repository=' . $repo : '');
}

function rpm_list(array $packages) {
  $info = [];
  $repos = ['oss' => 'x86_64', 'src-oss' => 'src'];
  foreach ($packages as $list) {
    foreach ($list as $package) {
      // kernel-source package is preferred since it has the desired devel parent.
      $repo = $package['binary'] == 'kernel-source' ? 'src-oss' : 'oss';
      $arch = $repos[$repo];

      // Filter to desired package since full list include over 15k entries.
      $query = '?P=' . $package['binary'] . '*';

      if ($xml = xml_fetch("tumbleweed/repo/$repo/suse/$arch$query", 'http://download.opensuse.org', true)) {
        foreach ($xml->xpath('//a[contains(@href, ".rpm") and not(contains(@href, ".mirrorlist"))]') as $link) {
          if ($package_info = rpm_version_extract($package['binary'], (string) $link['href'])) {
            $info[$package['binary']] = $package_info;
          } elseif (isset($info[$package['binary']])) {
            // Break at the first non-matching binary after matches.
            break;
          }
        }
      }
    }
  }
  return $info;
}

function rpm_version_extract($binary, $filename) {
    if (preg_match(str_replace('{binary}', $binary, RPM_VERSION_REGEX),
        $filename, $match)) {
    return [
      'binary' => $binary,
      'version' => $match[1],
    ];
  }

  return false;
}

// Modified from Drupal 7.x.
function format_interval($interval, $granularity = 2) {
  $units = array(
    '1 year|@count years' => 31536000,
    '1 month|@count months' => 2592000,
    '1 week|@count weeks' => 604800,
    '1 day|@count days' => 86400,
    '1 hour|@count hours' => 3600,
    '1 min|@count min' => 60,
    '1 sec|@count sec' => 1
  );
  $output = '';
  foreach ($units as $key => $value) {
    $key = explode('|', $key);
    if ($interval >= $value) {
      $count = floor($interval / $value);
      $output .= ($output ? ' ' : '') . ($count == 1 ? $key[0] : str_replace('@count', $count, $key[1]));
      $interval %= $value;
      $granularity--;
    }

    if ($granularity == 0) {
      break;
    }
  }
  return $output ? $output : '0 sec';
}

?>
<html>
  <head>
    <title>openSUSE Tumbleweed</title>
    <link rel='stylesheet prefetch' href='http://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css'>
    <link rel="stylesheet" href="style.css">
    <meta name="viewport" content="width=600">
    <link rel="shortcut icon" href="https://www.opensuse.org/assets/images/favicon.png">
  </head>
  <body>
    <div id="tumbleweed">
      <a href="https://en.opensuse.org/Portal:Tumbleweed">
        <img src="https://en.opensuse.org/images/c/c1/Tumbleweed.png" alt="openSUSE Tumbleweed">
      </a>
    </div>
    <div id="versions">
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
    <div id="last-updated">
    Last updated <?php print format_interval($ago); ?> ago. (updated hourly upon request)
    [<a href="https://github.com/boombatower/opensuse-tumbleweed-dashboard">source code</a>]
    </div>
  </body>
</html>
