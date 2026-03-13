<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."class/System.class.php";
include_once $dir."class/Database.class.php";

if ( ! Sys::checkAuth())
    die(header('Location: ../'));
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
        <button class="btn btn--secondary" x-data @click="$dispatch('mark-all-news')">Отметить все как прочитанные</button>
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
    @mark-all-news.window="markRead($el)"
>
<?php echo $news[$i]['text']?>
</div>
<?php
    }
}
