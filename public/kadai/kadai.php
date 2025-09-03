
<?php
$dbh = new PDO('mysql:host=mysql;dbname=example_db;charset=utf8mb4', 'root', '', [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
 
// 画像アップロード設定
define('UPLOAD_DIR', '/var/www/upload/image/');
define('MAX_IMAGE_BYTES', 5 * 1024 * 1024); // 5MB
// 許可MIMEと拡張子のマップ
$ALLOWED_IMAGE_MIME = [
  'image/jpeg' => 'jpg',
  'image/png' => 'png',
  'image/gif' => 'gif',
  'image/webp' => 'webp',
];
 
if (isset($_POST['body'])) {
  $image_filename = null;
 
  if (isset($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    // サーバー側サイズチェック（JS回避対策）
    if ($_FILES['image']['size'] > MAX_IMAGE_BYTES) {
      header("HTTP/1.1 302 Found");
      header("Location: ./kadai/kadai.php?err=too_large");
      exit;
    }
 
    // MIMEタイプ厳格判定（finfo）
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['image']['tmp_name']);
    if (!isset($ALLOWED_IMAGE_MIME[$mime])) {
      header("HTTP/1.1 302 Found");
      header("Location: ./kadai/kadai.php?err=bad_type");
      exit;
    }
 
    // 拡張子はMIMEから決定（元ファイル名に依存しない）
    $ext = $ALLOWED_IMAGE_MIME[$mime];
 
    // 一意なファイル名を生成
    $image_filename = sprintf('%d_%s.%s', time(), bin2hex(random_bytes(16)), $ext);
    $filepath = UPLOAD_DIR . $image_filename;
 
    // 保存先ディレクトリが無い/書けないと失敗するので注意
    if (!@move_uploaded_file($_FILES['image']['tmp_name'], $filepath)) {
      header("HTTP/1.1 302 Found");
      header("Location: ./kadai/kadai.php?err=save_failed");
      exit;
    }
  }
 
  // 投稿を保存（SQLインジェクション対策：prepare+バインド）
  $insert_sth = $dbh->prepare("INSERT INTO bbs_entries (body, image_filename) VALUES (:body, :image_filename)");
  $insert_sth->execute([
    ':body' => $_POST['body'],
    ':image_filename' => $image_filename,
  ]);
 
  // 二重投稿防止
  header("HTTP/1.1 302 Found");
  header("Location: ./kadai/kadai.php?ok=1");
  exit;
}
 
// 投稿一覧取得（created_at降順）
$select_sth = $dbh->prepare('SELECT * FROM bbs_entries ORDER BY created_at DESC');
$select_sth->execute();
$entries = $select_sth->fetchAll();
?>
<!doctype html>
<html lang="ja">
<meta charset="utf-8">
 
<!-- フォームのPOST先はこのファイル自身にする -->
<form method="POST" action="./kadai.php" enctype="multipart/form-data">
<textarea name="body" required style="width:100%;height:7em;"></textarea>
<div style="margin: 1em 0;">
<input type="file" accept="image/*" name="image" id="imageInput">
<div style="font-size:12px;color:#666;">※ 画像は5MB以下（JPEG/PNG/GIF/WebP）</div>
</div>
<button type="submit">送信</button>
</form>
 
<hr>
 
<?php foreach($entries as $entry): ?>
<dl style="margin-bottom: 1em; padding-bottom: 1em; border-bottom: 1px solid #ccc;">
<dt>ID</dt>
<dd><?= htmlspecialchars((string)$entry['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
 
    <dt>日時</dt>
<dd><?= htmlspecialchars((string)$entry['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
 
    <dt>内容</dt>
<dd>
<?= nl2br(htmlspecialchars((string)$entry['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
<?php if(!empty($entry['image_filename'])): ?>
<div style="margin-top: .5em;">
<img
          src="/image/<?= htmlspecialchars((string)$entry['image_filename'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          style="max-height: 10em;"
          alt="投稿画像">
</div>
<?php endif; ?>
</dd>
</dl>
<?php endforeach ?>
 
<script>
document.addEventListener("DOMContentLoaded", () => {
  const imageInput = document.getElementById("imageInput");
  if (!imageInput) return;
  imageInput.addEventListener("change", () => {
    if (imageInput.files.length < 1) return;
    if (imageInput.files[0].size > 5 * 1024 * 1024) {
      alert("5MB以下のファイルを選択してください。");
      imageInput.value = "";
    }
  });
});
</script>
</html>
