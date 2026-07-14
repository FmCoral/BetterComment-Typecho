<?php
/**
 * 评论头像自动解析插件
 *
 * 根据评论者填写的邮箱自动替换头像：
 *   - QQ 邮箱（数字@qq.com）：自动使用 QQ 头像
 *   - 非 QQ 邮箱：从插件 avatars/ 文件夹随机选取头像
 *
 * 未来规划：QQ 邮箱自动解析 QQ 昵称替换用户名
 *
 * @package CommentAvatar
 * @author FmCoral
 * @version 1.0.0
 * @link https://github.com/FmCoral
 */

namespace TypechoPlugin\CommentAvatar;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Widget\Options;

class Plugin implements PluginInterface
{
    /**
     * 激活插件
     *
     * @access public
     * @throws \Typecho\Plugin\Exception
     */
    public static function activate()
    {
        // 钩子：拦截评论头像输出
        // 注意：必须使用完整的命名空间类名，与 Widget\Base\Comments::pluginHandle() 中
        // static::class 返回的 "Widget\Base\Comments" 完全一致，不能用下划线简写。
        \Typecho\Plugin::factory('Widget\Base\Comments')->gravatar = [__CLASS__, 'renderAvatar'];

        // 确保头像目录存在
        $avatarsDir = __DIR__ . '/avatars';
        if (!is_dir($avatarsDir)) {
            @mkdir($avatarsDir, 0755, true);
        }

        // 确保缓存目录存在
        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
    }

    /**
     * 禁用插件
     *
     * @access public
     */
    public static function deactivate()
    {
        // 无需特殊操作，Typecho 自动清理钩子
    }

    /**
     * 插件配置面板
     *
     * @access public
     * @param Form $form
     */
    public static function config(Form $form)
    {
        // v1.0 暂无配置项
        // v1.1 计划：QQ 昵称覆盖开关、自定义头像大小等
    }

    /**
     * 个人用户配置
     *
     * @access public
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    // =========================================================================
    //  钩子回调
    // =========================================================================

    /**
     * 渲染评论头像（Widget_Comments->gravatar 钩子）
     *
     * 当插件注册此钩子后，Typecho 自动跳过默认 Gravatar 输出，
     * 由本方法完全接管头像 HTML 的生成。
     *
     * @access public
     * @param int    $size    头像尺寸
     * @param string $rating  评级（未使用）
     * @param string $default 默认头像（未使用）
     * @param object $comment 评论 Widget 对象
     * @return void
     */
    public static function renderAvatar($size, $rating, $default, $comment)
    {
        $email  = $comment->mail ?? '';
        $author = $comment->author ?? '';

        // 判断是否为 QQ 邮箱（纯数字 @qq.com）
        if (preg_match('/^(\d+)@qq\.com$/i', $email, $matches)) {
            $qq = $matches[1];
            // QQ 头像 API：s=100 获取 100x100 像素
            $avatarUrl = 'https://q1.qlogo.cn/g?b=qq&nk=' . $qq . '&s=100';
        } else {
            // 非 QQ 邮箱：从插件 avatars/ 文件夹随机选择
            $avatarUrl = self::getRandomAvatarUrl($email);
        }

        echo '<img class="avatar" loading="lazy" src="' . htmlspecialchars($avatarUrl) . '" '
           . 'alt="' . htmlspecialchars($author) . '" '
           . 'width="' . (int) $size . '" height="' . (int) $size . '" />';
    }

    // =========================================================================
    //  头像选择逻辑
    // =========================================================================

    /**
     * 为非 QQ 邮箱获取随机头像 URL
     *
     * 使用邮箱哈希确定性选择，保证同一邮箱始终获得相同头像。
     * 若 avatars/ 文件夹为空，则动态生成 SVG 占位头像作为降级。
     *
     * @access private
     * @param string $email 评论者邮箱
     * @return string 头像 URL
     */
    private static function getRandomAvatarUrl($email)
    {
        static $avatarList = null;
        static $pluginUrl = null;

        if ($avatarList === null) {
            $avatarList = self::listAvatarFiles();
        }

        if ($pluginUrl === null) {
            $pluginUrl = Options::alloc()->pluginUrl;
        }

        // 降级：没有头像文件时动态生成 SVG
        if (empty($avatarList)) {
            return self::generateSvgDataUri($email);
        }

        // 基于邮箱哈希确定性选取（同一邮箱始终同一头像）
        $index = abs(crc32($email)) % count($avatarList);
        $file  = $avatarList[$index];

        return \Typecho\Common::url(
            'CommentAvatar/avatars/' . $file,
            $pluginUrl
        );
    }

    /**
     * 列出 avatars/ 目录下所有图片文件
     *
     * @access private
     * @return array 文件名数组（已排序）
     */
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

    /**
     * 动态生成 SVG 头像 Data URI（降级方案）
     *
     * 基于邮箱哈希生成 HSL 颜色 + 首字母的组合头像，
     * 类似 Google/GitHub 的默认头像风格。
     *
     * @access private
     * @param string $email 邮箱
     * @return string SVG Data URI
     */
    private static function generateSvgDataUri($email)
    {
        $hash    = md5($email);
        $hue     = hexdec(substr($hash, 0, 2)) % 360;        // 色相 0-359
        $sat     = 55 + (hexdec(substr($hash, 2, 2)) % 20);  // 饱和度 55-74%
        $light   = 45 + (hexdec(substr($hash, 4, 2)) % 15);  // 亮度 45-59%

        // 取邮箱首字母作为头像文字
        $initial = mb_strtoupper(mb_substr($email, 0, 1));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
             . '<rect width="100" height="100" rx="10" fill="hsl(' . $hue . ',' . $sat . '%,' . $light . '%)"/>'
             . '<text x="50" y="50" dy=".1em" fill="#fff" font-family="Arial,sans-serif" '
             . 'font-size="46" font-weight="bold" text-anchor="middle" dominant-baseline="central">'
             . htmlspecialchars($initial)
             . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
