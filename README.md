1.EC2インスタンス作成
キーペアが保存されているファイルを右クリックしてプロパティを開く。セキュリティの欄の継承の無効化を押す。「継承されたアクセス許可をこのオブジェクトの明示的なアクセス許可に変換します。」を選択。その後ktc以外のプリンシパルを削除し、適用する

2.ssh ec2-user@{IPアドレス} -i {秘密鍵ファイルのパス}

3.docker および docker compose などのインストール
＄sudo yum install vim -y
＄sudo yum install screen -y
＄exit（再ログイン）
＄screen
＄sudo yum install -y docker
＄sudo systemctl start docker
＄sudo systemctl enable docker
＄sudo usermod -a -G docker ec2-user
＄sudo mkdir -p /usr/local/lib/docker/cli-plugins/
＄sudo curl -SL https://github.com/docker/compose/releases/download/v2.36.0/docker-compose-linux-x86_64 -o /usr/local/lib/docker/cli-plugins/docker-compose
＄sudo chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
＄docker compose version
＄exit（再ログイン）
＄mkdir dockertest
＄cd dockertest

＄vim Dockerfile
FROM php:8.4-fpm-alpine AS php
RUN docker-php-ext-install pdo_mysql
RUN install -o www-data -g www-data -d /var/www/upload/image/
RUN echo -e "post_max_size = 5M\nupload_max_filesize = 5M" >> ${PHP_INI_DIR}/php.ini

＄vim compose.yml
services:
  web:
    image: nginx:latest
    ports:
      - 80:80
    volumes:
      - ./nginx/conf.d/:/etc/nginx/conf.d/
      - ./public/:/var/www/public/
      - image:/var/www/upload/image/
    depends_on:
      - php
  php:
    container_name: php
    build:
      context: .
      target: php
    volumes:
      - ./public/:/var/www/public/
      - image:/var/www/upload/image/
  mysql:
    container_name: mysql
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: example_db
      MYSQL_ALLOW_EMPTY_PASSWORD: 1
      TZ: Asia/Tokyo
    volumes:
      - mysql:/var/lib/mysql
   command: >
      mysqld
      --character-set-server=utf8mb4
      --collation-server=utf8mb4_unicode_ci
      --max_allowed_packet=4MB
volumes:
  mysql:
  image:     

＄docker compose up --build

＄mkdir nginx/conf.d
＄sudo chown -R ec2-user:ec2-user nginx/conf.d

＄vim nginx/conf.d/default.conf
server {
    listen 80;
    server_name _;
    root /var/www/public;
    index index.html index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass php:9000;
    }
}

＄cd public
＄mkdir kadai
＄cd kadai
＄vim kadai.php

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
      header("Location: ./kadai.php?err=too_large");
      exit;
    }
 
    // MIMEタイプ厳格判定（finfo）
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['image']['tmp_name']);
    if (!isset($ALLOWED_IMAGE_MIME[$mime])) {
      header("HTTP/1.1 302 Found");
      header("Location: ./kadai.php?err=bad_type");
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
      header("Location: ./kadai.php?err=save_failed");
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
  header("Location: ./kadai.php?ok=1");
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

＄cd ~/dockertest

＄docker compose exec mysql mysql -u root example_db
＄CREATE DATABASE IF NOT EXISTS example_db
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

＄USE example_db;

＄DROP TABLE IF EXISTS bbs_entries;

＄CREATE TABLE bbs_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  body TEXT NOT NULL,                         -- 本文
  image_filename VARCHAR(255) DEFAULT NULL,   -- 画像ファイル名（任意）
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

＄exit
＄docker compose up --build

　http://パブリックIPアドレス/kadai/kadai.php　

途中までエラー内容
Warning: Cannot modify header information - headers already sent by (output started at /var/www/public/kadai/kadai.php:1) in /var/www/public/kadai/kadai.php on line 62

Warning: Cannot modify header information - headers already sent by (output started at /var/www/public/kadai/kadai.php:1) in /var/www/public/kadai/kadai.php on line 63

野田真優
https://github.com/Xc9crazy/kadai
http://3.81.4.127/kadai/kadai.php
