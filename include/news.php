<?php
$dir = dirname(__FILE__)."/../";
include_once $dir."class/System.class.php";
include_once $dir."class/Database.class.php";

if ( ! Sys::checkAuth())
    die(header('Location: ../'));
if (session_id() !== '') session_write_close();

$news = Database::getNews();
$hasNew = FALSE;
if ($news != NULL && is_array($news))
{
    foreach ($news as $n)
    {
        if ($n['new'])
        {
            $hasNew = TRUE;
            break;
        }
    }
}
?>

<div class="top-bar mb-2">
    <div class="top-bar__title"><svg><use href="assets/img/sprite.svg#news" /></svg> Новости</div>
    <?php if ($hasNew) { ?>
    <button class="btn btn--secondary" x-data="markAllNews">Отметить все как прочитанные</button>
    <?php } ?>
</div>

<?php
if ($news != NULL && is_array($news) && count($news) > 0)
{
    for ($i=0; $i<count($news); $i++)
    {
?>
<div x-data="news" class="content col --8:xl news-item<?= ($news[$i]['new']) ? ' news-item--new' : '' ?>" id="<?php echo $news[$i]['id']?>"
    data-news-id="<?= (int)$news[$i]['id'] ?>"
    @click="markRead($el, <?= (int)$news[$i]['id'] ?>)"
>
<?php echo Sys::sanitizeNewsHtml($news[$i]['text']) ?>
</div>
<?php
    }
}
