# Mr Ai Prime — URL / HTML to APK Builder

এই প্যাকেজে দেওয়া `index.html`-এর URL→APK ও HTML/ZIP→APK ফর্ম দুটো সম্পূর্ণ করা হয়েছে। আসল APK একটি GitHub Actions runner-এ Android WebView app হিসেবে build হয়; ওয়েব পেজটি শুধু APK file বানিয়ে দেওয়ার ভান করে না।

## কীভাবে কাজ করে

- **URL to APK:** Android WebView-এ আপনার দেওয়া HTTP/HTTPS website খোলে।
- **HTML/ZIP to APK:** `.html`/`.htm` সরাসরি, অথবা `index.html` থাকা ZIP-কে app-এর assets-এ রাখে।
- App name, package name, icon, portrait/landscape, এবং URL mode-এ pull-to-refresh সমর্থিত।
- Build result debug-signed, তাই Android ফোনে install করা যায়। Google Play release-এর জন্য আলাদা release keystore/signing setup লাগবে।

## ByetHost/shared hosting-এ চালু করার আগে একবারের setup

ByetHost-এর [প্রকাশিত free-hosting পেজে](https://byet.host/free-hosting) PHP 8.3, subdomain-এ free SSL এবং 10 MB সর্বোচ্চ upload size লেখা আছে। আপনার `byethost33` account-এর server setting আলাদা হতে পারে—নিচের `check-host.php` দিয়ে নিজের account-এ পরীক্ষা করুন।
ByetHost-এর বর্তমান free plan পেজে VistaPanel উল্লেখ আছে; আপনার `cpanel.byethost33.com` panel পুরনো বা account-specific হতে পারে। তাই নিচে File Manager/PHP settings-এর ধাপগুলো আপনার panel-এ যে নামে আছে সেই অনুযায়ী অনুসরণ করুন।

### 1. GitHub repository তৈরি করুন

এই ZIP-এর সব ফাইল একটি GitHub repository-র **default branch**-এ upload/push করুন। `.github/workflows/build-apk.yml` ওই branch-এ থাকতে হবে এবং repository-র Actions চালু থাকতে হবে।

GitHub-এর **Settings → Developer settings → Fine-grained personal access tokens** থেকে ওই repository-র জন্য token বানান:

- Repository access: শুধু এই repository
- Repository permissions: **Actions — Read and write**, **Contents — Read-only**

Token-টি website-এর private server config-এ রাখবেন; HTML, GitHub repo, বা chat-এ দেবেন না।

### 2. Hosting panel-এ ওয়েব app upload করুন

`cpanel.byethost33.com` হলো panel address; website চালাতে panel-এ দেখানো **আপনার website domain/document root**-এ app upload করতে হবে। Panel-এর **File Manager** খুলে domain-এর document root (`htdocs`, `public_html`, বা panel-এ দেখানো folder)-এ ZIP upload করে **Extract** করুন। `index.html`, `build-api.php`, `check-host.php`, `.github/`, `android/`-সহ সব ফাইল/ফোল্ডার থাকতে হবে।

Panel-এ PHP version **8.1+** নির্বাচন করুন (ByetHost-এর current free plan PHP 8.3 বলে)। HTTPS/SSL চালু করে website-এর HTTPS address ব্যবহার করুন—panel login URL নয়।

প্রথমে `https://আপনার-website/check-host.php` খুলুন। ফলাফলে `curlExtension`, `zipArchive`, `storageWritable`, `https`, এবং `githubApiReachable` true/ঠিক দেখাতে হবে। পরীক্ষা শেষে **`check-host.php` মুছে ফেলুন**। কোনো extension false হলে panel-এর PHP settings/selector থেকে চালু করুন; provider toggle করতে না দিলে hosting support-কে জিজ্ঞেস করুন।

### 3. GitHub settings private config-এ দিন

File Manager-এ `storage/config.example.php`-কে `storage/config.php` নামে copy করুন, তারপর `config.php`-তে এই মানগুলো বসান:

| Key | Value |
|---|---|
| `GITHUB_TOKEN` | Fine-grained token; শুধু এই repository, **Actions: Read and write**, **Contents: Read-only** |
| `GITHUB_REPOSITORY` | `owner/repository` |
| `APK_BUILDER_PUBLIC_URL` | আপনার HTTPS website URL-এর শেষে `/build-api.php` |
| `APK_BUILDER_SECRET` | কমপক্ষে 32টি random অক্ষর; File Manager-এর editor-এ server-side value দিন |
| `GITHUB_DEFAULT_BRANCH` | workflow রাখা branch; সাধারণত `main` |

`storage/.htaccess` folder-এর সরাসরি web access বন্ধ রাখে। **`config.php`-কে GitHub-এ upload/commit করবেন না**—এটি `.gitignore`-এ আছে। `storage/` PHP থেকে writable হতে হবে; permission `777` করবেন না।

`APK_BUILDER_SECRET` বানাতে panel-এ Terminal থাকলে `openssl rand -hex 32` চালান; না থাকলে 32+ অক্ষরের random value তৈরি করে শুধু `storage/config.php`-তে লিখুন—chat-এ পাঠাবেন না। File Manager দিয়ে token/secret file edit করুন।

### 4. Upload limits

ByetHost-এর প্রকাশিত free-plan limit 10 MB হওয়ায় builder-এ source ZIP/HTML সর্বোচ্চ **8 MB** এবং icon সর্বোচ্চ **1 MB** রাখা হয়েছে, যাতে multipart upload limit-এ না লাগে। ZIP extract হওয়ার পর 100 MB পর্যন্ত ও 5,000 ফাইল পর্যন্ত গ্রহণ করা হয়।

## Build করা

Setup-এর পর website খুলে:

1. **URL to APK** বেছে URL, app name, package name ও optional icon দিন; অথবা
2. **File to APK** বেছে HTML/ZIP দিন। ZIP-এ `index.html`/`index.htm` থাকতে হবে।
3. **APK বিল্ড শুরু করুন** চাপুন। Build শেষ হলে modal থেকে APK download হবে।

Download link 7 দিন কাজ করবে।

## Troubleshooting

- **Backend setup বাকি:** `storage/config.php`-এর চারটি required value এবং HTTPS URL পরীক্ষা করুন।
- **GitHub API unreachable / request failed:** `check-host.php`-এর `githubApiReachable` false হলে shared host outbound request block করতে পারে; ByetHost support-কে জিজ্ঞেস করুন, অথবা backend-কে outbound HTTPS অনুমোদিত hosting-এ রাখুন।
- **GitHub Actions-এ build পাঠানো যায়নি:** token permission/expiry, `owner/repository`, default branch এবং workflow default branch-এ আছে কিনা দেখুন।
- **Build শেষ হয় না বা ব্যর্থ:** GitHub repository → **Actions** → সংশ্লিষ্ট `APK Build ...` run খুলে log দেখুন।
- **APK install হয় না:** GitHub workflow `assembleDebug`-এর APK তৈরি করে; অন্য Android signature-এ আগে থেকে ইনস্টল করা একই package থাকলে সেটি uninstall করে নতুন APK install করুন।
- **URL app-এ খোলে না:** website-টি ফোনের browser-এ reachable কিনা যাচাই করুন। কিছু website Android WebView block করে বা browser-only features চায়।

URL mode website-এর live URL-ই WebView-এ খোলে; website ব্যবহার করতে ফোনে internet দরকার। HTML/ZIP mode-এর source app-এর ভেতরে embed হয়, তবে HTML-এ বাহ্যিক API/font/script থাকলে সেগুলোর জন্য internet লাগতে পারে।