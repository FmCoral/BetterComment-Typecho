<?php
/**
 * BetterComment — 全能评论增强插件（头像 / IP 属地 / 邮件通知 / 找回密码）
 *
 *
 * - 头像：QQ 邮箱自动用 QQ 头像，其他邮箱随机匹配预设头像
 * - IP 属地：评论旁显示 IP 地理位置（ip-api.com / pconline 双 API）
 * - 邮件通知：评论时通知文章作者、被回复者；审核通过通知评论者
 * - 找回密码：登录页"忘记密码"链接，邮件重置密码
 *
 * @package BetterComment
 * @author  FmCoral
 * @version 1.0.0
 * @link    https://github.com/FmCoral/BetterComment-Typecho
 */

namespace TypechoPlugin\BetterComment;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Form\Element\Submit;
use Typecho\Widget\Helper\Layout;
use Widget\Options;

class Plugin implements PluginInterface
{
    // =========================================================================
    //  生命周期
    // =========================================================================

    public static function activate()
    {
        // 检查 CURL（邮件发送必需）
        if (!function_exists('curl_init')) {
            throw new \Typecho\Plugin\Exception(_t('对不起，使用邮件发送功能必须要支持 CURL'));
        }

        // === 头像 / IP 属地钩子 ===
        \Typecho\Plugin::factory('Widget\Base\Comments')->gravatar = [__CLASS__, 'renderAvatar'];

        // === 邮件通知钩子 ===
        \Typecho\Plugin::factory('Widget\Feedback')->finishComment = [__CLASS__, 'doComment'];
        \Typecho\Plugin::factory('Widget\Comments\Edit')->finishComment = [__CLASS__, 'doComment'];
        \Typecho\Plugin::factory('Widget\Comments\Edit')->mark = [__CLASS__, 'doApproved'];

        // === 找回密码钩子 ===
        \Typecho\Plugin::factory('admin/footer.php')->end = [__CLASS__, 'forgetLink'];
        \Helper::addAction('commentavatar', 'TypechoPlugin\BetterComment\Action');
        \Helper::addRoute('commentavatar_forget', '/commentavatar/forget', 'TypechoPlugin\BetterComment\Action', 'forget');
        \Helper::addRoute('commentavatar_reset', '/commentavatar/reset', 'TypechoPlugin\BetterComment\Action', 'reset');

        // 确保资源目录存在
        foreach (['avatars', 'cache'] as $sub) {
            $dir = __DIR__ . '/' . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    public static function deactivate()
    {
        \Helper::removeAction('commentavatar');
        \Helper::removeRoute('commentavatar_forget');
        \Helper::removeRoute('commentavatar_reset');
    }

    public static function config(Form $form)
    {
        // =====================================================================
        //  1. IP 属地设置
        // =====================================================================
        $section1 = new Layout('div', ['class' => 'typecho-page-title']);
        $section1->html('<h2>🌍 IP 属地显示</h2>');
        $form->addItem($section1);

        $showLocation = new Checkbox(
            'showLocation',
            ['enable' => _t('显示评论 IP 属地')],
            ['enable'],
            _t('IP 属地显示'),
            _t('启用后在每条评论头像旁显示 IP 地理位置（如"广东 · 深圳"）。')
        );
        $form->addInput($showLocation);

        $apiProvider = new Radio(
            'apiProvider',
            [
                'ip-api'   => _t('ip-api.com（国际，中英文）'),
                'pconline' => _t('太平洋 pconline（国内，JSON）'),
                'ipshudi'  => _t('ipshudi（国内，HTML 抓取）'),
            ],
            'ip-api',
            _t('IP 查询服务'),
            _t('三个免费 API 可选，国内推荐太平洋或 ipshudi。')
        );
        $form->addInput($apiProvider);

        // =====================================================================
        //  2. 邮件发送 — 公共配置
        // =====================================================================
        $section2 = new Layout('div', ['class' => 'typecho-page-title']);
        $section2->html('<h2>📧 邮件通知设置</h2>');
        $form->addItem($section2);

        $public_interface = new Radio(
            'public_interface',
            [
                'smtp'     => _t('SMTP'),
                'sendcloud' => _t('Send Cloud'),
                'aliyun'   => _t('阿里云推送'),
            ],
            null,
            _t('发信接口')
        );
        $form->addInput($public_interface->addRule('required', _t('请选择发件接口')));

        $public_name = new Text(
            'public_name', null, null,
            _t('发件人名称'),
            _t('邮件中显示的发信人名称，留空为博客名称')
        );
        $form->addInput($public_name);

        $public_mail = new Text(
            'public_mail', null, null,
            _t('发件邮箱地址'),
            _t('邮件中显示的发信地址')
        );
        $form->addInput($public_mail->addRule('required', _t('请输入发件邮箱地址'))->addRule('email', _t('请输入正确的邮箱地址')));

        $public_replyto = new Text(
            'public_replyto', null, null,
            _t('邮件回复地址'),
            _t('附带在邮件中的默认回信地址')
        );
        $form->addInput($public_replyto->addRule('required', _t('请输入回信邮箱地址'))->addRule('email', _t('请输入正确的邮箱地址')));

        $public_debug = new Checkbox(
            'public_debug',
            ['enable' => _t('启用 Debug')],
            ['enable'],
            _t('Debug 模式'),
            _t('启用后将在插件目录生成 debug.txt 文件，记录邮件发送详细错误')
        );
        $form->addInput($public_debug);

        $public_verify = new Checkbox(
            'public_verify',
            ['enable' => _t('启用配置验证')],
            ['enable'],
            _t('配置验证'),
            _t('保存配置时验证 SMTP 连接 / SendCloud API 是否正确。启用后可能导致配置保存速度缓慢。使用 SSL 465 端口可能导致验证失败，建议使用 TLS 587 端口。')
        );
        $form->addInput($public_verify);

        // =====================================================================
        //  3. SMTP 设置
        // =====================================================================
        $section3 = new Layout('div', ['class' => 'typecho-page-title', 'data-interface-group' => 'smtp']);
        $section3->html('<h2>🔐 SMTP 邮件发送设置</h2>');
        $form->addItem($section3);

        $smtp_host = new Text('smtp_host', null, null, _t('SMTP 地址'), _t('SMTP 服务器连接地址'));
        $form->addInput($smtp_host);

        $smtp_port = new Text('smtp_port', null, null, _t('SMTP 端口'), _t('SMTP 服务器连接端口'));
        $form->addInput($smtp_port);

        $smtp_user = new Text('smtp_user', null, null, _t('SMTP 登录用户'), _t('SMTP 登录用户名，一般为邮箱地址'));
        $form->addInput($smtp_user);

        $smtp_pass = new Text('smtp_pass', null, null, _t('SMTP 登录密码'), _t('一般为邮箱密码，某些服务商需生成专用密码'));
        $form->addInput($smtp_pass);

        $smtp_auth = new Checkbox(
            'smtp_auth',
            ['enable' => _t('服务器需要验证')],
            ['enable'],
            _t('SMTP 验证模式')
        );
        $form->addInput($smtp_auth);

        $smtp_secure = new Radio(
            'smtp_secure',
            [
                'none' => _t('无安全加密'),
                'ssl'  => _t('SSL 加密'),
                'tls'  => _t('TLS 加密'),
            ],
            'none',
            _t('SMTP 加密模式')
        );
        $form->addInput($smtp_secure);

        // =====================================================================
        //  4. SendCloud 设置
        // =====================================================================
        $section4 = new Layout('div', ['class' => 'typecho-page-title', 'data-interface-group' => 'sendcloud']);
        $section4->html('<h2>☁️ Send Cloud 设置</h2>');
        $form->addItem($section4);

        $sendcloud_api_user = new Text('sendcloud_api_user', null, null, _t('API USER'), _t('请填入在 SendCloud 生成的 API_USER'));
        $form->addInput($sendcloud_api_user);

        $sendcloud_api_key = new Text('sendcloud_api_key', null, null, _t('API KEY'), _t('请填入在 SendCloud 生成的 API_KEY'));
        $form->addInput($sendcloud_api_key);

        // =====================================================================
        //  5. 阿里云推送设置
        // =====================================================================
        $section5 = new Layout('div', ['class' => 'typecho-page-title', 'data-interface-group' => 'aliyun']);
        $section5->html('<h2>🎯 阿里云推送设置</h2>');
        $form->addItem($section5);

        $ali_region = new Select(
            'ali_region',
            [
                'hangzhou'  => _t('华东 1（杭州）'),
                'singapore' => _t('亚太东南 1（新加坡）'),
                'sydney'    => _t('亚太东南 2（悉尼）'),
            ],
            null,
            _t('DM 接入区域'),
            _t('请选择您的邮件推送所在服务器区域')
        );
        $form->addInput($ali_region);

        $ali_accesskey_id = new Text('ali_accesskey_id', null, null, _t('AccessKey ID'), _t('请填入在阿里云生成的 AccessKey ID'));
        $form->addInput($ali_accesskey_id);

        $ali_accesskey_secret = new Text('ali_accesskey_secret', null, null, _t('Access Key Secret'), _t('请填入在阿里云生成的 Access Key Secret'));
        $form->addInput($ali_accesskey_secret);

        // =====================================================================
        //  6. 找回密码设置
        // =====================================================================
        $section6 = new Layout('div', ['class' => 'typecho-page-title']);
        $section6->html('<h2>🔑 找回密码设置</h2>');
        $form->addItem($section6);

        $public_forget = new Checkbox(
            'public_forget',
            ['enable' => _t('启用找回密码')],
            ['enable'],
            _t('找回密码'),
            _t('启用后，登录界面将出现"忘记密码"链接，通过邮件重置密码')
        );
        $form->addInput($public_forget);

        $public_expire = new Text(
            'public_expire', null, '10',
            _t('验证过期时间（分钟）'),
            _t('找回密码链接的有效时间，单位为分钟')
        );
        $form->addInput($public_expire);

        // =====================================================================
        //  JS：根据选中的发信接口动态显示/隐藏对应设置区块
        // =====================================================================
        $js = new Layout('div');
        $js->html(<<<EOS
<script>
(function() {
    var toggleInterface = function(selected) {
        document.querySelectorAll('[data-interface-group]').forEach(function(heading) {
            var iface = heading.getAttribute('data-interface-group');
            var show  = (iface === selected);
            heading.style.display = show ? '' : 'none';
            // 隐藏/显示后续元素，遇到任意 typecho-page-title 就停止（保护找回密码区域和保存按钮）
            var el = heading.nextElementSibling;
            while (el && !el.classList.contains('typecho-page-title')) {
                el.style.display = show ? '' : 'none';
                el = el.nextElementSibling;
            }
        });
    };

    var radios = document.querySelectorAll('input[name="public_interface"]');
    radios.forEach(function(r) {
        r.addEventListener('change', function() {
            if (this.checked) toggleInterface(this.value);
        });
    });

    var checked = document.querySelector('input[name="public_interface"]:checked');
    toggleInterface(checked ? checked.value : 'none');
})();
</script>
EOS);
        $form->addItem($js);
    }

    public static function personalConfig(Form $form) {}

    /**
     * 保存配置时自动验证
     */
    public static function configCheck(array $settings)
    {
        $options = \Helper::options();
        $plugin  = $options->plugin('BetterComment');

        if (!in_array('enable', $plugin->public_verify)) {
            return;
        }

        switch ($settings['public_interface']) {
            case 'sendcloud':
                if (empty($settings['sendcloud_api_user']) || empty($settings['sendcloud_api_key'])) {
                    return _t('Send Cloud API USER 与 API KEY 必须填写');
                }
                $url = 'http://api.sendcloud.net/apiv2/apiuser/list?apiUser='
                     . $settings['sendcloud_api_user'] . '&apiKey=' . $settings['sendcloud_api_key'];
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $result = curl_exec($ch);
                curl_close($ch);
                $result = json_decode($result);
                if (200 != $result->statusCode) {
                    return _t($result->message);
                }
                break;

            case 'aliyun':
                if (empty($settings['ali_region']) || empty($settings['ali_accesskey_id']) || empty($settings['ali_accesskey_secret'])) {
                    return _t('阿里云接入区域、AccessKey ID、Access Key Secret 必须填写');
                }
                break;

            default: // SMTP
                if (empty($settings['smtp_host'])) {
                    return _t('SMTP 地址必须填写');
                }
                if (empty($settings['smtp_port'])) {
                    return _t('SMTP 端口必须填写');
                }
                if (!class_exists('PHPMailer\PHPMailer\SMTP')) {
                    require __DIR__ . '/lib/SMTP.php';
                }
                $smtp = new \PHPMailer\PHPMailer\SMTP();

                $hostname = 'localhost.localdomain';
                if (isset($_SERVER) && array_key_exists('SERVER_NAME', $_SERVER) && !empty($_SERVER['SERVER_NAME'])) {
                    $hostname = $_SERVER['SERVER_NAME'];
                } elseif (function_exists('gethostname') && gethostname() !== false) {
                    $hostname = gethostname();
                } elseif (php_uname('n') !== false) {
                    $hostname = php_uname('n');
                }

                if (!$smtp->connect($settings['smtp_host'], $settings['smtp_port'], 5)) {
                    return _t('SMTP 连接失败，请检查 SMTP 地址及端口');
                }
                if (!$smtp->hello($hostname)) {
                    return _t('SMTP 发送 EHLO 指令失败：' . $smtp->getError()['error']
                        . '。若使用 SSL 465 端口可能导致此错误，建议更换 TLS 587 端口');
                }

                $e = $smtp->getServerExtList();
                if (is_array($e) && array_key_exists('STARTTLS', $e)) {
                    if ('tls' != $settings['smtp_secure']) {
                        return _t('SMTP 服务器要求 tls 加密');
                    }
                    if (!$smtp->startTLS()) {
                        return _t('TLS 加密失败：' . $smtp->getError()['error']);
                    }
                    if (!$smtp->hello($hostname)) {
                        return _t('TLS 后 EHLO 失败：' . $smtp->getError()['error']);
                    }
                    $e = $smtp->getServerExtList();
                }

                if ((is_array($e) && array_key_exists('AUTH', $e)) || in_array('enable', $settings['smtp_auth'])) {
                    if (empty($settings['smtp_user']) || empty($settings['smtp_pass'])) {
                        return _t('SMTP 登录账号及密码不能为空');
                    }
                    if (!in_array('enable', $settings['smtp_auth'])) {
                        return _t('SMTP 服务器要求身份验证');
                    }
                    if (!$smtp->authenticate($settings['smtp_user'], $settings['smtp_pass'])) {
                        return _t('SMTP 登录失败：' . $smtp->getError()['error']);
                    }
                }
                $smtp->quit(true);
                break;
        }
    }

    // =========================================================================
    //  头像 + IP 属地
    // =========================================================================

    /**
     * 渲染评论头像 + IP 属地标签
     */
    public static function renderAvatar($size, $rating, $default, $comment)
    {
        $email  = $comment->mail ?? '';
        $author = $comment->author ?? '';

        // --- 头像 URL ---
        if (preg_match('/^(\d+)@qq\.com$/i', $email, $matches)) {
            $avatarUrl = 'https://q1.qlogo.cn/g?b=qq&nk=' . $matches[1] . '&s=100';
        } else {
            $avatarUrl = self::getRandomAvatarUrl($email);
        }

        echo '<img class="avatar" loading="lazy" src="' . htmlspecialchars($avatarUrl) . '" '
           . 'alt="' . htmlspecialchars($author) . '" '
           . 'width="' . (int) $size . '" height="' . (int) $size . '" />';

        // --- IP 属地标签 ---
        if (self::isLocationEnabled()) {
            $ip = $comment->ip ?? '';
            if ($ip && $ip !== 'unknown') {
                $location = self::getIpLocation($ip);
                if ($location && $location !== '未知' && $location !== '本地网络') {
                    $short = self::formatLocationShort($location);
                    if ($short) {
                        echo '<span class="cm-ip-loc" style="display:inline-block;margin-left:3px;'
                           . 'padding:0 5px;border-radius:2px;background:#f2f3f5;color:#999;'
                           . 'font-size:.65rem;line-height:1.6;vertical-align:middle;'
                           . 'max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                           . htmlspecialchars($short) . '</span>';
                    }
                }
            }
        }
    }

    /**
     * 根据邮箱哈希选取随机头像
     */
    private static function getRandomAvatarUrl($email)
    {
        static $avatarList = null;
        static $pluginUrl  = null;

        if ($avatarList === null) {
            $avatarList = self::listAvatarFiles();
        }
        if ($pluginUrl === null) {
            $pluginUrl = Options::alloc()->pluginUrl;
        }

        if (empty($avatarList)) {
            return self::generateSvgDataUri($email);
        }

        $index = abs(crc32($email)) % count($avatarList);
        return \Typecho\Common::url('BetterComment/avatars/' . $avatarList[$index], $pluginUrl);
    }

    private static function listAvatarFiles()
    {
        $dir   = __DIR__ . '/avatars';
        $files = [];
        if (is_dir($dir)) {
            $glob = glob($dir . '/*.{png,jpg,jpeg,gif,svg,webp}', GLOB_BRACE);
            if ($glob) {
                foreach ($glob as $f) {
                    $files[] = basename($f);
                }
            }
        }
        sort($files);
        return $files;
    }

    private static function generateSvgDataUri($email)
    {
        $hash   = md5($email);
        $hue    = hexdec(substr($hash, 0, 2)) % 360;
        $sat    = 55 + (hexdec(substr($hash, 2, 2)) % 20);
        $light  = 45 + (hexdec(substr($hash, 4, 2)) % 15);
        $initial = mb_strtoupper(mb_substr($email, 0, 1));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
             . '<rect width="100" height="100" rx="10" fill="hsl(' . $hue . ',' . $sat . '%,' . $light . '%)"/>'
             . '<text x="50" y="50" dy=".1em" fill="#fff" font-family="Arial,sans-serif" '
             . 'font-size="46" font-weight="bold" text-anchor="middle" dominant-baseline="central">'
             . htmlspecialchars($initial) . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    // =========================================================================
    //  IP 地理位置查询
    // =========================================================================

    /**
     * 获取 IP 地理位置
     *
     * 优先复用 IpAccessLog 插件的缓存，未安装时使用内置查询。
     */
    public static function getIpLocation($ip)
    {
        if ($ip === 'unknown' || empty($ip)) {
            return '未知';
        }
        if (self::isPrivateIp($ip)) {
            return '本地网络';
        }

        // 复用 IpAccessLog 插件缓存（如果已安装）
        if (class_exists('\TypechoPlugin\IpAccessLog\Plugin')) {
            return \TypechoPlugin\IpAccessLog\Plugin::getIpLocation($ip);
        }

        // 内置查询：读缓存 → API → 写缓存
        $cacheFile = self::getIpCacheFile();
        $cache = [];
        if (file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
        }
        if (isset($cache[$ip])) {
            return $cache[$ip];
        }

        $location = '未知';
        try {
            $provider = self::getApiProvider();
            if ($provider === 'pconline') {
                $result = self::queryPconline($ip);
            } elseif ($provider === 'ipshudi') {
                $result = self::queryIpShudi($ip);
            } else {
                $result = self::queryIpApi($ip);
            }
            if ($result) {
                $location = $result;
            }
        } catch (\Exception $e) {
            // 静默失败
        }

        // 写缓存（上限 5000 条）
        $cache[$ip] = $location;
        if (count($cache) > 5000) {
            $cache = array_slice($cache, -4000, 4000, true);
        }
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $location;
    }

    private static function queryIpApi($ip)
    {
        $url = 'http://ip-api.com/json/' . urlencode($ip)
             . '?lang=zh-CN&fields=country,regionName,city,isp';
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) {
            return '';
        }

        $data = json_decode($json, true);
        if (!$data || !isset($data['country'])) {
            return '';
        }

        $parts = [];
        if (!empty($data['country']))    $parts[] = $data['country'];
        if (!empty($data['regionName'])) $parts[] = $data['regionName'];
        if (!empty($data['city']))       $parts[] = $data['city'];
        if (!empty($data['isp']))        $parts[] = $data['isp'];

        return implode(' ', $parts);
    }

    private static function queryPconline($ip)
    {
        $url = 'https://whois.pconline.com.cn/ipJson.jsp?ip=' . urlencode($ip) . '&json=true';
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) {
            return '';
        }

        $json = mb_convert_encoding($json, 'UTF-8', 'GBK');
        $data = json_decode($json, true);
        if (!$data) {
            return '';
        }

        $parts = [];
        if (!empty($data['pro'])) {
            $parts[] = $data['pro'];
        }
        if (!empty($data['city']) && $data['city'] !== $data['pro']) {
            $parts[] = $data['city'];
        }

        if (empty($parts) && !empty($data['addr'])) {
            $addr = trim($data['addr']);
            if ($addr && !in_array($addr, ['局域网', '本机地址', '保留地址'])) {
                $addrClean = preg_replace('/\s+\S+$/', '', $addr);
                $parts[] = $addrClean ?: $addr;
            }
        }

        return $parts ? implode(' ', $parts) : '';
    }

    private static function queryIpShudi($ip)
    {
        $url = 'https://www.ipshudi.com/' . urlencode($ip) . '.htm';
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header'  => "User-Agent: Mozilla/5.0\r\n",
            ],
        ]);
        $html = @file_get_contents($url, false, $ctx);
        if (!$html) {
            return '';
        }

        $location = '';
        if (preg_match('#<td class="th">归属地</td>\s*<td>\s*<span>([^<]+)</span>#i', $html, $m)) {
            $location = trim($m[1]);
        }
        if (preg_match('#<td class="th">运营商</td>\s*<td>\s*<span>([^<]+)</span>#i', $html, $m)) {
            $isp = trim($m[1]);
            if ($isp !== '' && !in_array($isp, ['-', '未知'])) {
                $location .= ' ' . $isp;
            }
        }
        return $location;
    }

    public static function formatLocationShort($location)
    {
        $parts = explode(' ', trim($location));
        $parts = array_values(array_filter($parts, function ($v) {
            return $v !== '' && $v !== '未知';
        }));

        $count = count($parts);
        if ($count === 0) {
            return '';
        }

        if ($count <= 2) {
            return implode(' · ', $parts);
        }

        $lastIsIsp = in_array(end($parts), ['电信', '联通', '移动', '铁通', '教育网', '鹏博士', '长城宽带']);
        $meaningful = $lastIsIsp ? array_slice($parts, 0, -1) : $parts;

        if (count($meaningful) <= 1) {
            return $meaningful[0];
        }

        if ($meaningful[0] === '中国') {
            array_shift($meaningful);
        }

        if (count($meaningful) === 1) {
            return $meaningful[0];
        }

        if (count($meaningful) >= 2 && $meaningful[0] === $meaningful[1]) {
            array_shift($meaningful);
        }

        return implode(' · ', $meaningful);
    }

    private static function isPrivateIp($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && $ip !== 'unknown';
    }

    private static function getIpCacheFile()
    {
        return __DIR__ . '/cache/ip_locations.json';
    }

    private static function getApiProvider()
    {
        static $provider = null;
        if ($provider === null) {
            try {
                $config = Options::alloc()->plugin('BetterComment');
                $config = $config ? $config->toArray() : [];
            } catch (\Exception $e) {
                $config = [];
            }
            $val = $config['apiProvider'] ?? 'ip-api';
            if (is_array($val)) {
                $val = implode('', $val);
            }
            $provider = in_array($val, ['ip-api', 'pconline', 'ipshudi'], true) ? $val : 'ip-api';
        }
        return $provider;
    }

    private static function isLocationEnabled()
    {
        static $enabled = null;
        if ($enabled === null) {
            try {
                $config = Options::alloc()->plugin('BetterComment');
                $config = $config ? $config->toArray() : [];
            } catch (\Exception $e) {
                $config = [];
            }
            $enabled = in_array('enable', $config['showLocation'] ?? ['enable'], true);
        }
        return $enabled;
    }

    // =========================================================================
    //  邮件通知
    // =========================================================================

    /**
     * 评论提交后的通知钩子
     */
    public static function doComment($comment)
    {
        self::sendMail($comment->coid);
    }

    /**
     * 评论审核状态变化钩子（仅审核通过时发送通知）
     */
    public static function doApproved($comment, $edit, $status)
    {
        if ('approved' === $status) {
            self::sendMail(
                is_object($comment) ? $comment->coid : ($comment['coid'] ?? 0),
                true
            );
        }
    }

    /**
     * 发送邮件通知
     *
     * @param int  $commentId  评论编号
     * @param bool $isApproved 是否为审核通过通知
     * @return bool
     */
    public static function sendMail($commentId, $isApproved = false)
    {
        if (!$commentId) {
            return false;
        }

        // 直接查 DB 获取评论数据（数组），绕过 Widget 初始化复杂性
        $comment = self::fetchRow('comments', 'coid', $commentId);
        if (!$comment) {
            return false;
        }

        $options = \Helper::options();
        $plugin  = $options->plugin('BetterComment');

        // 获取关联文章，用 Typecho 实际路由生成正确链接
        $post  = self::fetchRow('contents', 'cid', $comment['cid']);
        $title = $post['title'] ?? '';
        $postUrl = self::getContentUrl($post, $options);
        $commentUrl = $postUrl . '#comment-' . $comment['coid'];
        $permalink  = $postUrl;

        // 确定收件人
        $address       = $comment['mail'];
        $parentComment = null;

        // 如果评论者不是文章作者 → 通知作者
        if (($comment['authorId'] ?? 0) != ($comment['ownerId'] ?? 0)) {
            $author = self::fetchRow('users', 'uid', $comment['ownerId']);
            if ($author && !empty($author['mail'])) {
                $address = $author['mail'];
            }
        }

        // 如果是回复 → 通知被回复者
        if (0 < ($comment['parent'] ?? 0)) {
            $parentComment = self::fetchRow('comments', 'coid', $comment['parent']);
            if ($parentComment && $comment['mail'] != $parentComment['mail']) {
                $address = $parentComment['mail'];
            }
        }

        $data = [
            'fromName' => (!empty($plugin->public_name)) ? $plugin->public_name : trim($options->title),
            'from'     => $plugin->public_mail,
            'to'       => $address,
            'replyTo'  => $plugin->public_replyto,
        ];

        if ($isApproved) {
            $data['subject'] = $options->title . '：您的评论已通过审核';
            $html = @file_get_contents(__DIR__ . '/theme/approved.html');
            if (!$html) return false;
            $data['html'] = str_replace(
                ['{blogUrl}', '{blogName}', '{author}', '{permalink}', '{title}', '{text}'],
                [
                    trim($options->siteUrl), trim($options->title),
                    trim($comment['author'] ?? ''), trim($permalink),
                    trim($title), trim($comment['text'] ?? ''),
                ],
                $html
            );
        } elseif (!is_null($parentComment)) {
            $data['subject'] = $options->title . '：您的评论有了新的回复';
            $html = @file_get_contents(__DIR__ . '/theme/reply.html');
            if (!$html) return false;
            $data['html'] = str_replace(
                ['{blogUrl}', '{blogName}', '{author}', '{permalink}', '{title}', '{text}',
                 '{replyAuthor}', '{replyText}', '{commentUrl}'],
                [
                    trim($options->siteUrl), trim($options->title),
                    trim($parentComment['author'] ?? ''), trim($permalink),
                    trim($title), trim($parentComment['text'] ?? ''),
                    trim($comment['author'] ?? ''), trim($comment['text'] ?? ''),
                    trim($commentUrl),
                ],
                $html
            );
        } else {
            $data['subject'] = $options->title . '：文章有了新评论';
            $html = @file_get_contents(__DIR__ . '/theme/author.html');
            if (!$html) return false;
            $data['html'] = str_replace(
                ['{blogUrl}', '{blogName}', '{author}', '{permalink}', '{title}', '{text}'],
                [
                    trim($options->siteUrl), trim($options->title),
                    trim($comment['author'] ?? ''), trim($permalink),
                    trim($title), trim($comment['text'] ?? ''),
                ],
                $html
            );
        }

        // 根据接口发送
        switch ($plugin->public_interface) {
            case 'sendcloud':
                $data['apiUser'] = $plugin->sendcloud_api_user;
                $data['apiKey']  = $plugin->sendcloud_api_key;
                return self::sendCloud($data);

            case 'aliyun':
                $regionMap = [
                    'hangzhou'  => ['api' => 'https://dm.aliyuncs.com/',                            'version' => '2015-11-23', 'region' => 'cn-hangzhou'],
                    'singapore' => ['api' => 'https://dm.ap-southeast-1.aliyuncs.com/',              'version' => '2017-06-22', 'region' => 'ap-southeast-1'],
                    'sydney'    => ['api' => 'https://dm.ap-southeast-2.aliyuncs.com/',              'version' => '2017-06-22', 'region' => 'ap-southeast-2'],
                ];
                $region = $regionMap[$plugin->ali_region] ?? $regionMap['hangzhou'];
                $data['api']         = $region['api'];
                $data['version']     = $region['version'];
                $data['region']      = $region['region'];
                $data['accessid']    = $plugin->ali_accesskey_id;
                $data['accesssecret'] = $plugin->ali_accesskey_secret;
                return self::aliyun($data);

            default: // SMTP
                $data['smtp_host']   = $plugin->smtp_host;
                $data['smtp_port']   = $plugin->smtp_port;
                $data['smtp_user']   = $plugin->smtp_user;
                $data['smtp_pass']   = $plugin->smtp_pass;
                $data['smtp_auth']   = $plugin->smtp_auth;
                $data['smtp_secure'] = $plugin->smtp_secure;
                return self::smtp($data);
        }
    }

    // =========================================================================
    //  SendCloud 邮件发送
    // =========================================================================

    public static function sendCloud($data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://api.sendcloud.net/apiv2/mail/send');
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        curl_close($ch);

        $flag = true;
        $plugin = \Helper::options()->plugin('BetterComment');

        if (in_array('enable', $plugin->public_debug)) {
            $log = '[Send Cloud] ' . date('Y-m-d H:i:s') . ':' . PHP_EOL;
            if ($errno) {
                $flag = false;
                $log .= _t('邮件发送失败, 错误代码：' . $errno . '，错误提示: ' . $error . PHP_EOL);
            }
            $json = json_decode($result);
            if ($json && 200 != $json->statusCode) {
                $flag = false;
                $log .= _t('邮件发送失败，错误提示：' . $json->message . PHP_EOL);
            }
            $log .= _t('返回数据：' . serialize($result) . PHP_EOL);
            $log .= '-------------------------------------------' . PHP_EOL . PHP_EOL;
            @file_put_contents(__DIR__ . '/debug.txt', $log, FILE_APPEND);
        }

        return $flag;
    }

    // =========================================================================
    //  阿里云邮件发送
    // =========================================================================

    public static function aliyun($param)
    {
        $data = [
            'Action'          => 'SingleSendMail',
            'AccountName'     => $param['from'],
            'ReplyToAddress'  => 'true',
            'AddressType'     => 1,
            'ToAddress'       => $param['to'],
            'FromAlias'       => $param['fromName'],
            'Subject'         => $param['subject'],
            'HtmlBody'        => $param['html'],
            'Format'          => 'JSON',
            'Version'         => $param['version'],
            'AccessKeyId'     => $param['accessid'],
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp'       => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce'  => md5(time() . uniqid()),
            'RegionId'        => $param['region'],
        ];
        $data['Signature'] = self::sign($data, $param['accesssecret']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_URL, $param['api']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, self::getPostHttpBody($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $result  = curl_exec($ch);
        $errno   = curl_errno($ch);
        $error   = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $flag = true;
        $plugin = \Helper::options()->plugin('BetterComment');

        if (in_array('enable', $plugin->public_debug)) {
            $log = '[Aliyun] ' . date('Y-m-d H:i:s') . ':' . PHP_EOL;
            if ($errno) {
                $flag = false;
                $log .= _t('邮件发送失败, 错误代码：' . $errno . '，错误提示: ' . $error . PHP_EOL);
            }
            if (400 <= $httpCode) {
                $flag = false;
                $json = json_decode($result);
                if ($json) {
                    $log .= _t('错误代码：' . $json->Code . '，错误提示：' . $json->Message . PHP_EOL);
                } else {
                    $log .= _t('HTTP Code：' . $httpCode . PHP_EOL);
                }
            }
            $log .= _t('返回数据：' . serialize($result) . PHP_EOL);
            $log .= '-------------------------------------------' . PHP_EOL . PHP_EOL;
            @file_put_contents(__DIR__ . '/debug.txt', $log, FILE_APPEND);
        }

        return $flag;
    }

    // =========================================================================
    //  SMTP 邮件发送
    // =========================================================================

    public static function smtp($param)
    {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            require __DIR__ . '/lib/PHPMailer.php';
        }
        if (!class_exists('PHPMailer\PHPMailer\SMTP')) {
            require __DIR__ . '/lib/SMTP.php';
        }
        if (!class_exists('PHPMailer\PHPMailer\Exception')) {
            require __DIR__ . '/lib/Exception.php';
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(false);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host     = $param['smtp_host'];
        $mail->Port     = $param['smtp_port'] ?: 25;
        $mail->Username = $param['smtp_user'];
        $mail->Password = $param['smtp_pass'];

        if (in_array('enable', $param['smtp_auth'])) {
            $mail->SMTPAuth = true;
        }
        if ('none' != $param['smtp_secure']) {
            $mail->SMTPSecure = $param['smtp_secure'];
        }

        $mail->setFrom($param['from'], $param['fromName']);
        $mail->addReplyTo($param['replyTo'], $param['fromName']);
        $mail->addAddress($param['to']);
        $mail->isHTML(true);

        // Debug 级别：仅 Debug 模式开启时输出
        $plugin = \Helper::options()->plugin('BetterComment');
        $mail->SMTPDebug = in_array('enable', $plugin->public_debug) ? 2 : 0;

        $mail->Subject  = $param['subject'];
        $mail->msgHTML($param['html']);

        $result = $mail->send();

        if (in_array('enable', $plugin->public_debug)) {
            $log  = '[SMTP] ' . date('Y-m-d H:i:s') . ':' . PHP_EOL;
            $log .= 'data: ' . serialize($param) . PHP_EOL . PHP_EOL;
            $log .= _t('返回：' . var_export($result, true) . '; 错误: ' . $mail->ErrorInfo . PHP_EOL);
            $log .= '-------------------------------------------' . PHP_EOL . PHP_EOL;
            @file_put_contents(__DIR__ . '/debug.txt', $log, FILE_APPEND);
        }

        return $result;
    }

    // =========================================================================
    //  找回密码 — 登录页链接
    // =========================================================================

    /**
     * 在登录页底部添加"忘记密码"链接
     */
    public static function forgetLink()
    {
        $options = \Helper::options();
        $plugin  = $options->plugin('BetterComment');

        if (!in_array('enable', $plugin->public_forget)) {
            return;
        }

        $request   = \Typecho\Request::getInstance();
        $pathinfo  = $request->getRequestUrl();

        if (preg_match('/\/login\.php/i', $pathinfo)) {
            $url = \Typecho\Common::url(
                '/action/commentavatar?forget',
                $options->index
            );
            ?>
            <script>
                var forget = document.createElement('a');
                forget.href = '<?php echo $url; ?>';
                var text = document.createTextNode('<?php _e('忘记密码');?>');
                forget.appendChild(text);
                document.getElementsByClassName('more-link')[0].appendChild(forget);
            </script>
            <?php
        }
    }

    // =========================================================================
    //  工具方法
    // =========================================================================

    /**
     * 获取 Widget 对象
     *
     * @param string $table 数据表名（Comments / Users / Contents）
     * @param string $key   查询字段
     * @param mixed  $val   查询值
     * @return mixed
     */
    /**
     * 直接查 DB 获取单行数据（绕过 Widget 复杂初始化）
     */
    private static function fetchRow(string $table, string $key, $val): ?array
    {
        try {
            $db = \Typecho\Db::get();
            return $db->fetchRow(
                $db->select()->from('table.' . $table)
                    ->where($key . ' = ?', $val)->limit(1)
            ) ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // =========================================================================
    //  内容链接生成
    // =========================================================================

    /**
     * 根据 Typecho 路由表生成内容页面的正确链接
     *
     * @param array $post    contents 表行数据（需含 cid, slug, type, created 等）
     * @param mixed $options 站点选项对象
     * @return string
     */
    private static function getContentUrl(array $post, $options): string
    {
        $type = $post['type'] ?? 'post';

        // 页面：用 slug 生成 /{slug}.html
        if ('page' === $type) {
            if (null !== \Typecho\Router::get('page')) {
                return \Typecho\Router::url('page', ['slug' => $post['slug'] ?? ''], $options->siteUrl);
            }
            return \Typecho\Common::url('/' . ($post['slug'] ?? '') . '.html', $options->siteUrl);
        }

        // 文章：用 cid 生成 /archives/{cid}/
        if (null !== \Typecho\Router::get('post')) {
            return \Typecho\Router::url('post', ['cid' => $post['cid'] ?? 0], $options->siteUrl);
        }
        return \Typecho\Common::url('/archives/' . ($post['cid'] ?? 0) . '/', $options->siteUrl);
    }

    // =========================================================================
    //  阿里云签名
    // =========================================================================

    private static function sign($param, $accesssecret)
    {
        ksort($param);
        $stringToSign = 'POST&' . self::percentEncode('/') . '&';
        $tmp = '';
        foreach ($param as $k => $v) {
            $tmp .= '&' . self::percentEncode($k) . '=' . self::percentEncode($v);
        }
        $tmp = trim($tmp, '&');
        $stringToSign .= self::percentEncode($tmp);
        return base64_encode(
            hash_hmac('sha1', $stringToSign, $accesssecret . '&', true)
        );
    }

    private static function percentEncode($val)
    {
        $res = urlencode($val);
        $res = str_replace('+', '%20', $res);
        $res = str_replace('*', '%2A', $res);
        $res = str_replace('%7E', '~', $res);
        return $res;
    }

    private static function getPostHttpBody($param)
    {
        $str = '';
        foreach ($param as $k => $v) {
            $str .= $k . '=' . urlencode($v) . '&';
        }
        return substr($str, 0, -1);
    }
}
