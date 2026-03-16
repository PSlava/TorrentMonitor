<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."config.php";

if ( ! Sys::checkAuth())
    die(header('Location: ../'));
if (session_id() !== '') session_write_close();
?>

<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#news" /></svg> Новости</div>
    <?php
    $news = Database::getNews();
    $hasNew = false;
    if ($news != NULL && is_array($news)) {
        foreach ($news as $n) { if ($n['new']) { $hasNew = true; break; } }
    }
    if ($hasNew) { ?>
        <button class="btn btn--secondary" x-data="markAllNews">Отметить все как прочитанные</button>
    <?php } ?>
</div>

<?php
if ($news != NULL && is_array($news) && count($news) > 0)
{
    for ($i=0; $i<count($news); $i++)
    {
?>
<div x-data="news" class="content col --8:xl news-item<?= ($news[$i]['new']) ? ' news-item--new' : '' ?>"
    data-news-id="<?= $news[$i]['id'] ?>"
>
<?php echo strip_tags($news[$i]['text'], '<a><b><strong><i><em><br><p><ul><ol><li><h3><h4><code>') ?>
</div>
<?php
    }
}
