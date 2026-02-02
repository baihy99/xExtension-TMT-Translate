# FreshRSS 腾讯云翻译扩展
## 功能
1. 插件配置中勾选需要翻译的订阅源，调用腾讯云机器翻译API将文章标题/正文翻译为中文；
2. 为节省API额度，默认仅翻译文章标题，若需翻译正文可在插件配置中勾选启用；
3. 翻译后的标题前添加「[译]」标识，正文保留原格式；
4. 翻译结果以持久化存储的方式存入数据库，翻译结果不丢失，也不重复翻译；
5. 使用腾讯云官方SDK，API连接稳定可靠，若开启付费API需关注使用额度；
6. 异常处理：翻译失败不影响文章正常加载，错误信息记录在FreshRSS日志中。

## 前置要求
1. 腾讯云账号开通「机器翻译（TMT）」服务；
2. 拥有腾讯云API密钥（SecretId/SecretKey），并授予TMT相关权限；
3. FreshRSS服务器可访问外网（能调用腾讯云API接口）。

## 安装步骤
1. 将本扩展目录`xExtension-TMT-Translate`放入FreshRSS的`extensions/`目录下；
```bash
cd /var/www/FreshRSS/extensions
sudo git clone https://github.com/baihy99/xExtension-TMT-Translate.git
sudo chmod -R 775 /var/www/FreshRSS/extensions/xExtension-TMT-Translate
sudo chown -R www-data:freshrss /var/www/FreshRSS/extensions/xExtension-TMT-Translate
```
2. 进入FreshRSS根目录，安装腾讯云SDK；
```bash
cd /var/www/FreshRSS/extensions/xExtension-TMT-Translate
composer require tencentcloud/tmt --update-no-dev
```
3. 登录FreshRSS后台，启用该扩展（配置→扩展→Tencent Translate→启用）；
4. 修改腾讯云配置（SecretId/SecretKey/Region/Endpoint）；
5. Region/Endpoint配置参考文档：https://cloud.tencent.com/product/tmt；
6. 选择需要翻译的订阅源，以及是否需要翻译正文；
7. 刷新RSS订阅，新拉取的文章将自动触发翻译。

## 注意
进入勾选翻译的订阅源文章列表会有延迟，这是正常现象。

## 腾讯云计费
腾讯云机器翻译有**免费额度**（每月500万字符），超出后按实际使用量计费，详情参考：https://cloud.tencent.com/product/tmt/pricing
