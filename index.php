<?php
	include_once 'backend/includes/helpers.php';
	include_once 'names.php';

	// check if a specific video has been requested
	if (isset($_GET['video'])) {
		// get raw query so it doesn't try to parse the reserved characters (;/?:@&=+,$)
		// the `substr` call removes the "video=" from the start
		$identifier = substr($_SERVER['QUERY_STRING'],6);
		$video = identifierToFileData($identifier);

		if ($video !== false) {
			$series = $video['series'];
			$title = $video['title'];
			$filename = $video['file'];
			$pagetitle = (isset($video['egg']) ? 'Secret~' : ($title . ' from ' . $series));
			$description = '';
		} else {
			header('HTTP/1.0 404 Not Found');
			include_once 'backend/pages/notfound.php';
			die();
		}
	} else { // Otherwise pick a random video
		$series = array_rand($names);
		$title = array_rand($names[$series]);
		$video = $names[$series][$title];
		$filename = $video['file'];
		$pagetitle = 'Anime Openings';
		$description = 'Anime openings from hundreds of series in high-quality';
	}

	$identifier = filenameToIdentifier($filename);
	$filename = rawurlencode($filename);

	$songKnown = array_key_exists('song', $video);
	if ($songKnown) {
		$songTitle = $video['song']['title'];
		$songArtist = $video['song']['artist'];
		if ($description == '')
			$description = 'Song: &quot;' . $songTitle . '&quot; by ' . $songArtist;
	}

	$subtitlesAvailable = array_key_exists('subtitles', $video);
	$subtitleAttribution = $subtitlesAvailable ? ('[' . $video['subtitles'] . ']') : '';

	$isEgg = isset($video['egg']);

	$baseURL = 'https://' . $WEBSITE_URL . substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/') + 1);
	$oembedURL = $baseURL . 'api/oembed/?url=' . $baseURL . '?' . $_SERVER['QUERY_STRING'];
?>
<!DOCTYPE html>
<html prefix="og: http://ogp.me/ns#">
	<head>
		<!-- Basic Page Stuff -->
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php echo $pagetitle; ?></title>
		<meta name="description" content="<?php echo $description; ?>">

		<!-- oEmbed Discovery -->
		<link rel="alternate" type="application/json+oembed" href="<?php echo $oembedURL; ?>&format=json" title="<?php echo $pagetitle; ?>">
		<link rel="alternate" type="text/xml+oembed" href="<?php echo $oembedURL; ?>&format=xml" title="<?php echo $pagetitle; ?>">

		<!-- Open Graph Tags -->
		<meta property="og:type" content="video.other">
		<meta property="og:url" content="<?php echo $baseURL . '?video=' . $identifier; ?>">
		<meta property="og:site_name" content="<?php echo $WEBSITE_URL; ?>">
		<meta property="og:title" content="<?php echo $pagetitle; ?>">
		<meta property="og:description" content="<?php echo $description; ?>">
		<meta property="og:image" content="<?php echo $baseURL; ?>assets/logo/512px.png"><?php
			foreach ($video['mime'] as $mime) {
				$ext = mimeToExt($mime);
				$content = ' content="' . $baseURL . 'video/' . $filename . $ext . '"';
				echo "\n\t\t" . '<meta property="og:video"' . $content . '>';
				echo "\n\t\t" . '<meta property="og:video:url"' . $content . '>';
				echo "\n\t\t" . '<meta property="og:video:secure_url"' . $content . '>';
				echo "\n\t\t" . '<meta property="og:video:type" content="' . htmlspecialchars($mime) . '">';
			}
			echo PHP_EOL;
		?>

		<!-- Facebook App Link -->
		<meta property="al:web:url" content="<?php echo $baseURL . '?video=' . $identifier; ?>">

		<!-- CSS and JS Resources -->
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.5.0/css/font-awesome.min.css">
		<link rel="stylesheet" type="text/css" href="CSS/main.css">
		<link rel="stylesheet" type="text/css" href="CSS/fonts.css">
		<link rel="stylesheet" type="text/css" href="CSS/subtitles.css">
		<script type="text/javascript">
			// Set config values from PHP into JavaScript.
			window.config = {
				VIDEO_INDEX_PADDING: <?php echo $VIDEO_INDEX_PADDING; ?>,
				USE_FILENAME_AS_IDENTIFIER: <?php echo ($USE_FILENAME_AS_IDENTIFIER ? 'true' : 'false') . PHP_EOL; ?>
			};
		</script>
		<script src="JS/main.js"></script>
		<script defer src="JS/subtitles.js"></script>

		<!-- Web App Tags -->
		<meta name="theme-color" content="#E58B00">
		<meta name="mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

		<!-- Logo Links -->
		<link href="/assets/logo/152px.png" rel="apple-touch-icon">
		<link href="/assets/logo/16px.png" rel="icon" sizes="16x16">
		<link href="/assets/logo/32px.png" rel="icon" sizes="32x32">
		<link href="/assets/logo/64px.png" rel="icon" sizes="64x64">
		<link href="/assets/logo/152px.png" rel="icon" sizes="152x152">
		<link href="/assets/logo/512px.png" rel="icon" sizes="512x512">
	</head>
	<body>
		<div id="wrapper">
			<video id="bgvid" loop preload="none"><?php
					foreach ($video['mime'] as $mime) {
						$ext = mimeToExt($mime);
						echo "\n\t\t\t\t" . '<source src="video/' . $filename . $ext . '" type="' . htmlspecialchars($mime) . '">';
					}
					echo PHP_EOL;
				?>
			</video>
		</div>

		<i id="giant-play-button" class="overlay fa fa-play quadbutton quadNotMobile"></i>

		<div id="progressbar" class="progressbar">
			<div class="progress">
				<div id="bufferprogress" class="progress"></div>
				<div id="timeprogress" class="progress"></div>
			</div>
		</div>

		<div class="displayTopRight"></div>

		<a id="menubutton" href="/hub/faq.php" class="quadbutton fa fa-bars"></a>

		<div id="site-menu" hidden>
			<span id="closemenubutton" class="quadbutton fa fa-times"></span>

			<p id="title"><?php echo $title; ?> </p>
			<p id="source"><?php echo 'From ' . $series; ?></p>
			<span id="song"><?php // If we have the data, echo it
				if ($songKnown)
					echo 'Song: &quot;' . $songTitle . '&quot; by ' . $songArtist;
				else { // Otherwise, let's just pretend it never existed... or troll the user.
					if ($isEgg || mt_rand(0,100) == 1)
						echo 'Song: &quot;Sandstorm&quot; by Darude';
				} ?></span>
			<p id="subs"<?php if (!$subtitlesAvailable) echo ' style="display:none"'; ?>>Subtitles by <span id="subtitle-attribution"><?php echo $subtitleAttribution; ?></span></p>

			<ul id="linkarea">
				<li class="link"<?php if ($isEgg) echo ' hidden'; ?>><a href="?video=<?php if (!$isEgg) echo $identifier; ?>" id="videolink">Link to this video</a></li><?php
					foreach ($video['mime'] as $mime) {
						$ext = mimeToExt($mime);
						echo "\n\t\t\t\t" . '<li class="link videodownload"' . ($isEgg ? ' hidden' : '') . '><a href="video/' . (!$isEgg ? $filename . $ext : '') . '" download>Download this video as ' . substr($ext,1) . '</a></li>';
					}
					echo PHP_EOL;
				?>
				<li class="link"><a id="listlink" href="/list/">Video list</a></li>
				<li class="link"><a href="/hub/">Hub</a></li>
			</ul>

			<div class="accordion">
				<input type="checkbox" id="settings-checkbox">
				<label for="settings-checkbox">
					<i class="fa fa-chevron-right"></i>
					<i class="fa fa-chevron-down"></i>
					Saved settings
				</label>
				<form id="settings-form">
					<fieldset>
						<legend>Show Video Title</legend>
						<input id="show-title-checkbox" type="checkbox" checked><label for="show-title-checkbox">Yes</label>
						<label id="show-title-delay">after <input type="number" min="0" value="0" step="1"> seconds</label>
					</fieldset>
					<fieldset>
						<legend>Play</legend>
						<label><input checked name="videoType" type="radio" value="all">All</label>
						<label><input name="videoType" type="radio" value="op">Openings Only</label>
						<label><input name="videoType" type="radio" value="ed">Endings Only</label>
					</fieldset>
					<fieldset>
						<legend>On End</legend>
						<label><input checked name="autonext" type="radio" value="false">Repeat Video</label>
						<label><input name="autonext" type="radio" value="true">Get a New Video</label>
					</fieldset>
					<fieldset>
						<legend>Enable Subtitles</legend>
						<label><input checked id="subtitle-checkbox" type="checkbox">Yes</label>
					</fieldset>
					<fieldset>
						<legend>Volume</legend>
						<input id="volume-slider" type="range" min="0" max="100" value="100" step="1">
						<label for="volume-slider" id="volume-amount">100%</label>
					</fieldset>
				</form>
			</div>

			<div class="accordion">
				<input type="checkbox" id="keybindings-checkbox">
				<label for="keybindings-checkbox">
					<i class="fa fa-chevron-right"></i>
					<i class="fa fa-chevron-down"></i>
					Keyboard bindings
				</label>
				<table id="keybindings-table">
					<tr><th>Key</th><th>Action</th></tr>
					<tr><td>M</td><td>Open/Close Menu</td></tr>
					<tr><td>N</td><td>Get a new video</td></tr>
					<tr><td>S</td><td>Toggle subtitles (if available)</td></tr>
					<tr><td><span class="fa fa-arrow-left"></span>/<span class="fa fa-arrow-right"></span></td><td>Back/Forward 10 seconds</td></tr>
					<tr><td>Space</td><td>Pause/Play</td></tr>
					<tr><td>F</td><td>Toggle fullscreen</td></tr>
					<tr><td>Page Up/Down</td><td>Volume</td></tr>
					<tr><td>Scroll Wheel</td><td>Volume</td></tr>
				</table>
			</div>
		</div>

		<div id="tooltip" class="is-hidden"></div>

		<div class="controlsleft">
			<span id="videoTypeToggle" class="quadbutton fa fa-circle"></span>
			<span id="getnewvideo" class="quadbutton fa fa-refresh"></span>
			<span id="autonext" class="quadbutton fa fa-toggle-off"></span>
		</div>

		<div class="controlsright">
			<span id="subtitles-button" class="quadbutton fa fa-commenting-o"<?php if (!$subtitlesAvailable) echo ' style="display:none"'; ?>></span>
			<span id="skip-left" class="quadbutton fa fa-arrow-left"></span>
			<span id="skip-right" class="quadbutton fa fa-arrow-right"></span>
			<span id="pause-button" class="quadbutton fa fa-play"></span>
			<span id="fullscreen-button" class="quadbutton fa fa-expand"></span>
		</div>

		<span id="title-popup"></span>
		<div id="modal" class="overlay"><iframe></iframe></div>

		<?php include_once 'backend/includes/botnet.html'; ?>
	</body>
</html>
