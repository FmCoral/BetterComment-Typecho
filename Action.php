<?php
/**
 * BetterComment — 找回密码 Action 处理器
 *
 * @package BetterComment
 * @author  FmCoral
 * @version 1.0.0
 */

namespace TypechoPlugin\BetterComment;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends \Widget\Base\Users implements \Widget\ActionInterface
{
    /**
     * 插件配置
     *
     * @var mixed
     */
    private $_plugin = null;

    public function __construct($request, $response, $params = null)
    {
        parent::__construct($request, $response, $params);
        $this->_plugin = $this->options->plugin('BetterComment');
    }

    /**
     * 渲染页面
     */
    private function html($act = 'forget', $form = null)
    {
        $actionUrl = \Typecho\Common::url('/action/commentavatar', $this->options->index);
        ?>
        <!DOCTYPE html>
        <html class="no-js">
        <head>
            <meta charset="<?php $this->options->charset(); ?>">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <meta name="renderer" content="webkit">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php _e('%s - %s - Powered by Typecho', 'reset' == $act ? _t('重置密码') : _t('找回密码'), $this->options->title); ?></title>
            <meta name="robots" content="noindex, nofollow">
            <link rel="stylesheet" href="<?php $this->options->adminStaticUrl('css', 'normalize.css'); ?>">
            <link rel="stylesheet" href="<?php $this->options->adminStaticUrl('css', 'grid.css'); ?>">
            <link rel="stylesheet" href="<?php $this->options->adminStaticUrl('css', 'style.css'); ?>">
            <!--[if lt IE 9]>
            <script src="<?php $this->options->adminStaticUrl('js', 'html5shiv.js'); ?>"></script>
            <script src="<?php $this->options->adminStaticUrl('js', 'respond.js'); ?>"></script>
            <![endif]-->
        </head>
        <body class="body-100">
        <!--[if lt IE 9]>
        <div class="message error browsehappy" role="dialog">
            <?php _e('当前网页 <strong>不支持</strong> 你正在使用的浏览器。为了正常访问，请
            <a href="http://browsehappy.com/">升级你的浏览器</a>'); ?>。
        </div>
        <![endif]-->
        <div class="typecho-login-wrap">
            <div class="typecho-login">
                <h1><a href="http://typecho.org" class="i-logo">Typecho</a></h1>
                <?php $form->render(); ?>
            </div>
        </div>
        <script src="<?php $this->options->adminStaticUrl('js', 'jquery.js'); ?>"></script>
        <script src="<?php $this->options->adminStaticUrl('js', 'jquery-ui.js'); ?>"></script>
        <script src="<?php $this->options->adminStaticUrl('js', 'typecho.js'); ?>"></script>
        <script>
        (function () {
            $(document).ready(function () {
                (function () {
                    var prefix = '<?php echo \Typecho\Cookie::getPrefix(); ?>',
                        cookies = {
                            notice: $.cookie(prefix + '__typecho_notice'),
                            noticeType: $.cookie(prefix + '__typecho_notice_type'),
                            highlight: $.cookie(prefix + '__typecho_notice_highlight')
                        },
                        path = '<?php echo \Typecho\Cookie::getPath(); ?>';
                    if (!!cookies.notice && 'success|notice|error'.indexOf(cookies.noticeType) >= 0) {
                        var head = $('.typecho-head-nav'),
                            p = $('<div class="message popup ' + cookies.noticeType + '">'
                                + '<ul><li>' + $.parseJSON(cookies.notice).join('</li><li>')
                                + '</li></ul></div>'), offset = 0;
                        if (head.length > 0) {
                            p.insertAfter(head);
                            offset = head.outerHeight();
                        } else {
                            p.prependTo(document.body);
                        }
                        function checkScroll() {
                            if ($(window).scrollTop() >= offset) {
                                p.css({'position': 'fixed', 'top': 0});
                            } else {
                                p.css({'position': 'absolute', 'top': offset});
                            }
                        }
                        $(window).scroll(function () { checkScroll(); });
                        checkScroll();
                        p.slideDown(function () {
                            var t = $(this), color = '#C6D880';
                            if (t.hasClass('error'))    color = '#FBC2C4';
                            else if (t.hasClass('notice')) color = '#FFD324';
                            t.effect('highlight', {color: color})
                                .delay(5000).fadeOut(function () { $(this).remove(); });
                        });
                        $.cookie(prefix + '__typecho_notice', null, {path: path});
                        $.cookie(prefix + '__typecho_notice_type', null, {path: path});
                    }
                    if (cookies.highlight) {
                        $('#' + cookies.highlight).effect('highlight', 1000);
                        $.cookie(prefix + '__typecho_notice_highlight', null, {path: path});
                    }
                })();
            });
        })();
        </script>
        <?php if ('forget' == $act) : ?>
        <script>$(document).ready(function () { $('#mail').focus(); });</script>
        <?php endif; ?>
        </body>
        </html>
        <?php
    }

    /**
     * 忘记密码表单
     */
    private function forgetForm()
    {
        $form = new \Typecho\Widget\Helper\Form(
            $this->security->getIndex('action/commentavatar'),
            \Typecho\Widget\Helper\Form::POST_METHOD
        );

        $mail = new \Typecho\Widget\Helper\Form\Element\Text(
            'mail', null, null,
            _t('邮箱地址'),
            _t('请输入您注册时的邮箱地址')
        );
        $mail->input->setAttribute('class', 'text-l w-100');
        $mail->addRule('required', _t('必须输入您的邮箱地址'));
        $mail->addRule('email', _t('请输入正确的邮箱格式'));
        $form->addInput($mail);

        $do = new \Typecho\Widget\Helper\Form\Element\Hidden('do', null, 'forget');
        $form->addItem($do);

        $submit = new \Typecho\Widget\Helper\Form\Element\Submit('submit', null, _t('提交'));
        $submit->input->setAttribute('class', 'btn btn-l w-100 primary');
        $form->addItem($submit);

        return $form;
    }

    /**
     * 重置密码表单
     */
    private function resetForm($uid = 0)
    {
        $form = new \Typecho\Widget\Helper\Form(
            $this->security->getIndex('action/commentavatar'),
            \Typecho\Widget\Helper\Form::POST_METHOD
        );

        $password = new \Typecho\Widget\Helper\Form\Element\Password(
            'password', null, null,
            _t('用户密码'),
            _t('建议使用特殊字符与字母、数字的混编样式，以增加系统安全性。')
        );
        $password->input->setAttribute('class', 'text-l w-100');
        $password->addRule('required', _t('必须输入您的密码'));
        $password->addRule('minLength', _t('请设置最少 8 位数的密码'), 8);
        $form->addInput($password);

        $confirm = new \Typecho\Widget\Helper\Form\Element\Password(
            'confirm', null, null,
            _t('密码确认'),
            _t('请再次输入密码，与上面保持一致。')
        );
        $confirm->input->setAttribute('class', 'text-l w-100');
        $confirm->addRule('confirm', _t('两次输入的密码不一致'), 'password');
        $form->addInput($confirm);

        $do = new \Typecho\Widget\Helper\Form\Element\Hidden('do', null, 'reset');
        $form->addItem($do);

        $uidField = new \Typecho\Widget\Helper\Form\Element\Hidden('uid', null, $uid);
        $form->addItem($uidField);

        $submit = new \Typecho\Widget\Helper\Form\Element\Submit('submit', null, _t('提交'));
        $submit->input->setAttribute('class', 'btn btn-l w-100 primary');
        $form->addItem($submit);

        return $form;
    }

    /**
     * 找回密码提交
     */
    private function doForget()
    {
        if ($error = $this->forgetForm()->validate()) {
            $this->widget('Widget\Notice')->set($error, 'error');
            $this->response->goBack();
        }

        $user = $this->db->fetchRow(
            $this->select()->where('mail = ?', $this->request->mail)
        );
        if (!$user) {
            $this->widget('Widget\Notice')->set(_t('邮箱地址错误，请核对后重新输入'), 'error');
            $this->response->goBack();
        }

        $expire = $this->_plugin->public_expire ? (int) $this->_plugin->public_expire : 10;
        $time   = time() + $expire * 60;

        $query = [
            'reset' => 'true',
            't' => md5($user['uid'] . $user['name'] . $user['mail'] . $time),
            'm' => $user['mail'],
            'e' => $time,
        ];
        $uri = \Typecho\Common::url(
            '/action/commentavatar?' . http_build_query($query),
            $this->options->index
        );

        $data = [
            'fromName' => (!empty($this->_plugin->public_name))
                ? $this->_plugin->public_name
                : trim($this->options->title),
            'from'    => $this->_plugin->public_mail,
            'to'      => $user['mail'],
            'replyTo' => $this->_plugin->public_replyto,
            'subject' => _t('您在 [' . trim($this->options->title) . '] 提交的密码找回申请'),
        ];

        $html = file_get_contents(__DIR__ . '/theme/forget.html');
        $data['html'] = str_replace(
            ['{blogname}', '{blogurl}', '{mail}', '{sendtime}', '{resetlink}', '{expire}'],
            [
                trim($this->options->title),
                trim($this->options->siteUrl),
                trim($user['mail']),
                date('Y-m-d H:i:s'),
                trim($uri),
                (string) $expire,
            ],
            $html
        );

        $successMsg = _t('已将重置密码信息发送至您的注册邮箱，请注意查收！');
        $failMsg    = _t('邮件发送失败，请联系管理员解决！');

        switch ($this->_plugin->public_interface) {
            case 'sendcloud':
                $data['apiUser'] = $this->_plugin->sendcloud_api_user;
                $data['apiKey']  = $this->_plugin->sendcloud_api_key;
                if (!Plugin::sendCloud($data)) {
                    $this->widget('Widget\Notice')->set($failMsg, 'error');
                    $this->response->goBack();
                }
                break;

            case 'aliyun':
                $regionMap = [
                    'hangzhou'  => ['api' => 'https://dm.aliyuncs.com/',                  'version' => '2015-11-23', 'region' => 'cn-hangzhou'],
                    'singapore' => ['api' => 'https://dm.ap-southeast-1.aliyuncs.com/',    'version' => '2017-06-22', 'region' => 'ap-southeast-1'],
                    'sydney'    => ['api' => 'https://dm.ap-southeast-2.aliyuncs.com/',    'version' => '2017-06-22', 'region' => 'ap-southeast-2'],
                ];
                $region = $regionMap[$this->_plugin->ali_region] ?? $regionMap['hangzhou'];
                $data['api']         = $region['api'];
                $data['version']     = $region['version'];
                $data['region']      = $region['region'];
                $data['accessid']    = $this->_plugin->ali_accesskey_id;
                $data['accesssecret'] = $this->_plugin->ali_accesskey_secret;
                if (!Plugin::aliyun($data)) {
                    $this->widget('Widget\Notice')->set($failMsg, 'error');
                    $this->response->goBack();
                }
                break;

            default: // SMTP
                $data['smtp_host']   = $this->_plugin->smtp_host;
                $data['smtp_port']   = $this->_plugin->smtp_port;
                $data['smtp_user']   = $this->_plugin->smtp_user;
                $data['smtp_pass']   = $this->_plugin->smtp_pass;
                $data['smtp_auth']   = $this->_plugin->smtp_auth;
                $data['smtp_secure'] = $this->_plugin->smtp_secure;
                if (!Plugin::smtp($data)) {
                    $this->widget('Widget\Notice')->set($failMsg, 'error');
                    $this->response->goBack();
                }
                break;
        }

        $this->widget('Widget\Notice')->set($successMsg, 'success');
        $this->response->goBack();
    }

    /**
     * 重置密码页面
     */
    private function reset()
    {
        $expire = $this->request->filter('int')->e;
        if (time() > $expire) {
            $this->widget('Widget\Notice')->set(
                _t('抱歉，重置密码链接已过期，请重新获取'), 'notice'
            );
            $this->response->redirect(
                \Typecho\Common::url('/action/commentavatar?forget', $this->options->index)
            );
        }

        $user = $this->db->fetchRow(
            $this->select()->where('mail = ?', $this->request->m)
        );
        if (!$user) {
            $this->widget('Widget\Notice')->set(_t('抱歉，您的请求有误'), 'error');
            $this->response->redirect($this->options->loginUrl);
        }

        $token = $this->request->filter('strip_tags', 'trim', 'xss')->t;
        if ($token !== md5($user['uid'] . $user['name'] . $user['mail'] . $expire)) {
            $this->widget('Widget\Notice')->set(_t('抱歉，请求验证错误'), 'error');
            $this->response->redirect($this->options->loginUrl);
        }

        $this->html('reset', $this->resetForm($user['uid']));
    }

    /**
     * 重置密码提交
     */
    private function doReset()
    {
        if ($error = $this->resetForm()->validate()) {
            $this->widget('Widget\Notice')->set($error, 'error');
            $this->response->goBack();
        }

        $uid = $this->request->filter('integer')->uid;
        if (!$uid) {
            $this->widget('Widget\Notice')->set(_t('抱歉，请求验证失败'), 'error');
            $this->response->goBack();
        }

        // 密码加密
        $hasher   = new \PasswordHash(8, true);
        $password = $hasher->HashPassword($this->request->password);

        if ($this->update(
            ['password' => $password],
            $this->db->sql()->where('uid = ?', $uid)
        )) {
            $this->widget('Widget\Notice')->set(_t('密码重置成功'), 'success');
            $this->response->redirect($this->options->loginUrl);
        }

        $this->widget('Widget\Notice')->set(_t('密码重置失败，请联系管理员'), 'error');
        $this->response->redirect($this->options->loginUrl);
    }

    /**
     * 路由入口
     */
    public function action()
    {
        if ($this->user->hasLogin()) {
            $this->response->redirect($this->options->profileUrl);
        }

        if ($this->request->isPost()) {
            $this->on($this->request->is('do=forget'))->doForget();
            $this->on($this->request->is('do=reset'))->doReset();
        }

        if ($this->request->is('forget')) {
            $this->html('forget', $this->forgetForm());
        }
        if ($this->request->is('reset')) {
            $this->reset();
        }
    }
}
