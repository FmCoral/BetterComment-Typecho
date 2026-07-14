<?php
/**
 * 评论头像自动解析 + IP 属地显示插件
 *
 *   - QQ 邮箱（数字@qq.com）：自动使用 QQ 头像
 *   - 非 QQ 邮箱：从插件 avatars/ 文件夹随机选取头像
 *   - IP 属地：评论旁显示发评论者的 IP 地理位置（ip-api.com / pconline 双 API 可选）
 *
 * @package CommentAvatar
 * @author FmCoral
 * @version 1.3.1
 * @link https://github.com/FmCoral
 */

namespace TypechoPlugin\CommentAvatar;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Radio;
use Widget\Options;

class Plugin implements PluginInterface
{
    // =========================================================================
    //  生命周期
    // =========================================================================

    public static function activate()
    {
        \Typecho\Plugin::factory('Widget\Base\Comments')->gravatar = [__CLASS__, 'renderAvatar'];

        // 确保资源目录存在
        foreach (['avatars', 'cache'] as $sub) {
            $dir = __DIR__ . '/' . $sub;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
        }
    }

    public static function deactivate() {}

    public static function config(Form $form)
    {
        // IP 属地显示开关
        $showLocation = new Checkbox(
            'showLocation',
            ['enable' => _t('显示评论 IP 属地')],
            ['enable'],
            _t('IP 属地显示'),
            _t('启用后在每条评论头像旁显示发评论者的 IP 地理位置（如"广东 · 深圳"）。')
        );
        $form->addInput($showLocation);

        // IP 查询 API 选择
        $apiProvider = new Radio(
            'apiProvider',
            [
                'ip-api'  => _t('ip-api.com（国际，支持中英文城市名）'),
                'pconline' => _t('太平洋 pconline（国内，纯中文，速度快）'),
            ],
            'ip-api',
            _t('IP 查询服务'),
            _t('选择 IP 地理位置查询服务商。国内访问推荐太平洋，解析更精确；海外或需英文结果选 ip-api.com。')
        );
        $form->addInput($apiProvider);
    }

    public static function personalConfig(Form $form) {}

    // =========================================================================
    //  钩子回调
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

    // =========================================================================
    //  头像选择
    // =========================================================================

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
        return \Typecho\Common::url('CommentAvatar/avatars/' . $avatarList[$index], $pluginUrl);
    }

    private static function listAvatarFiles()
    {
        $dir   = __DIR__ . '/avatars';
        $files = [];
        if (is_dir($dir)) {
            $glob = glob($dir . '/*.{png,jpg,jpeg,gif,svg,webp}', GLOB_BRACE);
            if ($glob) foreach ($glob as $f) $files[] = basename($f);
        }
        sort($files);
        return $files;
    }

    private static function generateSvgDataUri($email)
    {
        $hash  = md5($email);
        $hue   = hexdec(substr($hash, 0, 2)) % 360;
        $sat   = 55 + (hexdec(substr($hash, 2, 2)) % 20);
        $light = 45 + (hexdec(substr($hash, 4, 2)) % 15);
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
     * 优先复用 IpAccessLog 插件（共享缓存文件），未安装时使用内置查询。
     */
    public static function getIpLocation($ip)
    {
        if ($ip === 'unknown' || empty($ip)) return '未知';
        if (self::isPrivateIp($ip)) return '本地网络';

        // 方式 1：复用 IpAccessLog 插件
        if (class_exists('\TypechoPlugin\IpAccessLog\Plugin')) {
            return \TypechoPlugin\IpAccessLog\Plugin::getIpLocation($ip);
        }

        // 方式 2：内置查询（读缓存 → API → 写缓存）
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
            $result = $provider === 'pconline' ? self::queryPconline($ip) : self::queryIpApi($ip);
            if ($result) $location = $result;
        } catch (\Exception $e) {}

        // 写缓存（上限 5000 条）
        $cache[$ip] = $location;
        if (count($cache) > 5000) {
            $cache = array_slice($cache, -4000, 4000, true);
        }
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $location;
    }

    /**
     * 调用 ip-api.com 在线查询
     */
    private static function queryIpApi($ip)
    {
        $url = 'http://ip-api.com/json/' . urlencode($ip)
             . '?lang=zh-CN&fields=country,regionName,city,isp';
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return '';

        $data = json_decode($json, true);
        if (!$data || !isset($data['country'])) return '';

        $parts = [];
        if (!empty($data['country'])) $parts[] = $data['country'];
        if (!empty($data['regionName'])) $parts[] = $data['regionName'];
        if (!empty($data['city'])) $parts[] = $data['city'];
        // 可选 ISP（国内显示运营商）
        if (!empty($data['isp']) && $data['isp'] !== '') {
            $parts[] = $data['isp'];
        }

        return implode(' ', $parts);
    }

    /**
     * 调用 pconline（太平洋网络）在线查询
     *
     * 返回中文省/市，GBK 编码需转 UTF-8。
     */
    private static function queryPconline($ip)
    {
        $url = 'https://whois.pconline.com.cn/ipJson.jsp?ip=' . urlencode($ip) . '&json=true';
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return '';

        // pconline 返回 GBK 编码，需转换
        $json = mb_convert_encoding($json, 'UTF-8', 'GBK');
        $data = json_decode($json, true);
        if (!$data || !empty($data['err'])) return '';

        $parts = [];
        if (!empty($data['pro']))  $parts[] = $data['pro'];
        if (!empty($data['city'])) $parts[] = $data['city'];
        // addr 包含详细地址，作为后备
        if (empty($parts) && !empty($data['addr'])) {
            // addr 格式：局域网 或 中国北京市 等
            $addr = trim($data['addr']);
            if ($addr && !in_array($addr, ['局域网', '本机地址', '保留地址'])) {
                $parts[] = $addr;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * 读取用户选择的 IP API 提供商
     */
    private static function getApiProvider()
    {
        static $provider = null;
        if ($provider === null) {
            try {
                $config = Options::alloc()->plugin('CommentAvatar');
                $config = $config ? $config->toArray() : [];
            } catch (\Exception $e) {
                $config = [];
            }
            $val = $config['apiProvider'] ?? 'ip-api';
            // 兼容 Typecho 可能将 Radio 值存为数组的情况
            if (is_array($val)) $val = implode('', $val);
            $provider = in_array($val, ['ip-api', 'pconline'], true) ? $val : 'ip-api';
        }
        return $provider;
    }

    /**
     * 将完整地理位置精简为短格式（用于评论旁标签）
     *
     * "中国 广东 深圳 电信" → "广东 · 深圳"
     * "中国 北京 北京"       → "北京"
     * "美国 加利福尼亚 洛杉矶" → "美国 · 洛杉矶"
     */
    public static function formatLocationShort($location)
    {
        $parts = explode(' ', trim($location));
        $parts = array_values(array_filter($parts, function ($v) {
            return $v !== '' && $v !== '未知';
        }));

        $count = count($parts);
        if ($count === 0) return '';

        if ($count <= 2) {
            return implode(' · ', $parts);
        }

        // 3+ 段：跳过第一段（国家，如"中国"），取省+市
        // 国外则保留国家+城市
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

        // 去重：省市区名相同时只保留一个
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

    // =========================================================================
    //  配置读取
    // =========================================================================

    private static function isLocationEnabled()
    {
        static $enabled = null;
        if ($enabled === null) {
            try {
                $config = Options::alloc()->plugin('CommentAvatar');
                $config = $config ? $config->toArray() : [];
            } catch (\Exception $e) {
                $config = [];
            }
            $enabled = in_array('enable', $config['showLocation'] ?? ['enable'], true);
        }
        return $enabled;
    }
}
