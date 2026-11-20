<?php
/**
 * opensearch.xml (served from here by .htaccess): lets Chrome, Edge, Firefox and Safari search
 * AngelWrites straight from the address bar (type the site's name, then Tab or space, then the words),
 * and add AngelWrites as a search engine. The header links to it on every page.
 */
require_once 'includes/config.php';
header('Content-Type: application/opensearchdescription+xml; charset=UTF-8');
$b = htmlspecialchars(rtrim(SITE_URL, '/'), ENT_XML1);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/" xmlns:moz="http://www.mozilla.org/2006/browser/search/">
  <ShortName>AngelWrites</ShortName>
  <Description>Search AngelWrites: poems, books, reflections, posts and videos by Angella Bottoman.</Description>
  <InputEncoding>UTF-8</InputEncoding>
  <Image width="16" height="16" type="image/x-icon"><?php echo $b; ?>/favicon.ico</Image>
  <Url type="text/html" method="get" template="<?php echo $b; ?>/search_results.php?q={searchTerms}"/>
  <moz:SearchForm><?php echo $b; ?>/search_results.php</moz:SearchForm>
</OpenSearchDescription>
