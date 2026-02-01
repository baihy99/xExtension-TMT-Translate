# FreshRSS 腾讯云翻译扩展
## 功能
1. 人为指定需要翻译的订阅源，调用腾讯云机器翻译API将文章标题/正文翻译为中文；
2. 翻译后的标题前添加「[译]」标识，正文保留原格式；
3. 异常处理：翻译失败不影响文章正常加载，错误信息记录在FreshRSS日志中。

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
4. 修改腾讯云配置（SecretId/SecretKey/Region/Endpoint），并选择需要翻译的订阅源；
5. 刷新RSS订阅，新拉取的文章将自动触发翻译。

## 腾讯云计费
腾讯云机器翻译有**免费额度**（每月500万字符），超出后按实际使用量计费，详情参考：https://cloud.tencent.com/product/tmt/pricing
