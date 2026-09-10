<?php
/** Local browser regression fixture: actual importer markup inside a post form. */
define( 'LUNARA_MOVIE_IMPORT_RENDER_ONLY', true );
$without_script = isset( $_GET['nojs'] );
ob_start();
require dirname( __DIR__ ) . '/movie-import-admin-regression.php';
$launcher = ob_get_clean();
if ( $without_script ) {
    $launcher = str_replace( '<dialog ', '<dialog open ', $launcher );
}
?>
<!doctype html>
<html lang="en"><meta charset="utf-8"><title>Movie lookup form regression</title>
<body>
<h1>Movie lookup form regression</h1>
<a href="?nojs=1">Check without editor JavaScript</a>
<p id="counts" role="status">Lookups: 0; Review saves: 0; Imports: 0</p>
<form id="post">
    <label>Review title <input name="post_title" value="Unchanged review title"></label>
    <?php echo $launcher; // Trusted local test renderer. ?>
    <label>Review body <textarea name="content">Unchanged review body</textarea></label>
    <button type="submit">Update Review</button>
</form>
<script>
window.LunaraMovieImportAdmin = {reviewId: 99, nonce: 'local-fixture', restBase: '/fixture/'};
let lookups = 0, saves = 0, imports = 0;
function showCounts() {
    document.getElementById('counts').textContent = `Lookups: ${lookups}; Review saves: ${saves}; Imports: ${imports}`;
}
document.addEventListener('submit', event => {
    if (event.target.id === 'post') { event.preventDefault(); saves++; showCounts(); }
});
// No remote requests or WordPress writes are possible in this fixture.
window.fetch = function (url) {
    if (url.endsWith('/lookup')) lookups++; else imports++;
    showCounts();
    return Promise.resolve({ok: true, json: () => Promise.resolve({candidate: {
        imdb_title_id: 'tt0068646', title: 'The Godfather', year: 1972
    }})});
};
</script>
<?php if ( ! $without_script ) : ?>
<script src="../../assets/js/lunara-movie-import-admin.js"></script>
<?php endif; ?>
</body></html>
