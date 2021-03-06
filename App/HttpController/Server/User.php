<?php
/**
 * 用户接口
 *
 *
 *
 *
 * @copyright  Copyright (c) 2019 MoJiKeJi Inc. (http://www.fashop.cn)
 * @license    http://www.fashop.cn
 * @link       http://www.fashop.cn
 * @since      File available since Release v1.1
 */

namespace App\HttpController\Server;

use App\Biz\AccessToken;
use App\Biz\Server\Binding as BindingLogic;
use App\Biz\Server\Login as LoginLogic;
use App\Biz\Server\Register as RegisterBiz;
use App\Biz\Server\Untie as UntieLogic;
use App\Biz\User as UserBiz;
use App\Utils\Code;

class User extends Server
{
	/**
	 * 刷新token
	 * @method POST
	 */
	public function token()
	{
		if( $this->verifyResourceRequest() !== true ){
			$this->send( Code::user_access_token_error );
		} else{
			$accessTokenLogic     = new AccessToken();
			$start_time           = time();
			$refresh_access_token = $accessTokenLogic->refreshAccessToken( $this->getRequestAccessToken(), $start_time );
			if( !$refresh_access_token ){
				$this->send( Code::error );
			} else{
				$this->send( Code::success, $refresh_access_token );
			}
		}
	}

	/**
	 * 登陆
	 * @http     POST
	 * @param string $login_type    password smscode  wechat_openid  wechat_mini wechat_app
	 * @param string $username
	 * @param string $password
	 * @param string $wechat_openid 微信openid ，登陆方式为wechat_openid 或者 wechat_app必填
	 * @param string $wechat_mini_code
	 * @author   韩文博
	 */
	public function login()
	{
		if(
			!isset( $this->post['login_type'] ) || !in_array( $this->post['login_type'], [
				'password',
				'wechat_openid',
				'wechat_mini',
				'wechat_app',
			] )
		){
			$this->send( Code::user_login_type_error );
		} else{

			$validate_scene['password']      = 'Server/Login.password';
			$validate_scene['wechat_openid'] = 'Server/Login.wechatOpenid';
			$validate_scene['wechat_mini']   = 'Server/Login.wechatMini';
			$validate_scene['wechat_app']    = 'Server/Login.wechatApp';

			if( $this->validator( $this->post, $validate_scene[$this->post['login_type']] ) !== true ){
				$this->send( $this->getValidator()->getError() );
			} else{
				try{
					$loginLogic   = new LoginLogic( (array)$this->post );
					$access_token = $loginLogic->login();
					if( $access_token ){
						$this->send( Code::success, $access_token );
					} else{
						// todo 返回错误code
						$this->send( Code::user_access_token_create_fail );
					}
				} catch( \Exception $e ){
					$this->send( Code::error, [], $e->getMessage() );
				}
			}
		}
	}

	/**
	 * 注册
	 * @method POST
	 * @param string $register_type     password wechat_openid  wechat_mini wechat_app
	 *                                  密码注册
	 * @param string $username
	 * @param string $password
	 * @param string $channel_type      sms email
	 * @param string $verify_code
	 *                                  微信注册
	 * @param string $wechat_openid
	 * @param string $wechat            文档写清楚这里面都有啥
	 *
	 * @param array  $wechat_mini_param (数组必须包含code，encryptedData，iv)
	 *
	 * @param string $wechat_openid     注册方式为wechat_openid 或者 wechat_app必填
	 * @param string $wechat_app        和$register_type为wechat_openid 参数一样
	 */
	public function register()
	{
		if(
			!isset( $this->post['register_type'] ) || !in_array( $this->post['register_type'], [
				'password',
				'wechat_openid',
				'wechat_mini',
				'wechat_app',

			] )
		){
			$this->send( Code::user_register_type_error );
		} else{
			$validate_scene['password']      = 'Server/Register.password';
			$validate_scene['wechat_openid'] = 'Server/Register.wechatOpenid';
			$validate_scene['wechat_mini']   = 'Server/Register.wechatMini';
			$validate_scene['wechat_app']    = 'Server/Register.wechatApp';

			if( $this->validator( $this->post, $validate_scene[$this->post['register_type']] ) !== true ){
				$this->send( code::param_error, [], $this->getValidator()->getError() );
			} else{
				try{
					$this->send( (new RegisterBiz( (array)$this->post ))->register() ? Code::success : Code::error );
				} catch( \App\Utils\Exception $e ){
					$this->send( $e->getCode() );
				}
			}
		}
	}

	/**
	 * 当前用户信息
	 * @method GET
	 */
	public function self()
	{
		if( $this->verifyResourceRequest() !== true ){
			return $this->send( Code::user_access_token_error );
		} else{
			$this->send( Code::success, [
				'info' => $this->getRequestUser(),
			] );
		}
	}

	/**
	 * 退出
	 * @http   GET
	 */
	public function logout()
	{
		if( $this->verifyResourceRequest() !== true ){
			return $this->send( Code::user_access_token_error );
		} else{
			$jwt    = $this->getRequestAccessTokenData();
			$result = \App\Model\AccessToken::init()->editAccessToken( [
				'jti' => $jwt['jti'],
			], [
				'is_logout'   => 1,
				'logout_time' => time(),
			] );

			if( $result ){
				return $this->send( Code::success );
			} else{
				return $this->send( Code::error );
			}
		}
	}

	/**
	 * 通过找回，修改密码
	 * @http   post
	 * @param string $phone
	 * @param string $password
	 * @param string $verify_code
	 */
	public function editPasswordByFind()
	{
		$this->post['channel_type'] = 'sms';
		$this->post['behavior']     = 'findPassword';
		if( $this->validator( $this->post, 'Server/FindPassword.phone' ) !== true ){
			$this->send( Code::error, [], $this->getValidator()->getError() );
		} else{
			$condition['phone'] = $this->post['phone'];
			$user_info          = \App\Model\User::init()->getUserInfo( $condition, "id" );
			\App\Model\User::init()->editUser( [
				'id' => $user_info['id'],
			], [
				'password' => UserBiz::encryptPassword( $this->post['password'] ),
			] );
			$this->send( Code::success );
		}
	}

	/**
	 * 修改密码
	 * @http   post
	 * @param string $oldpassword 老密码
	 * @param string $password    新密码
	 */
	public function editPassword()
	{
		if( $this->verifyResourceRequest() !== true ){
			$this->send( Code::user_access_token_error );
		} else{
			$user = $this->getRequestUser();

			if( $this->validator( $this->post, 'Server/EditPaasword.edit' ) !== true ){
				$this->send( Code::error, [], $this->getValidator()->getError() );
			} else{
				$result = \App\Model\User::init()->editUser( [
					'id'       => $user['id'],
					'password' => UserBiz::encryptPassword( $this->post['oldpassword'] ),
				], [
					'password' => UserBiz::encryptPassword( $this->post['password'] ),
				] );
				$this->send( $result ? Code::success : Code::error );
			}
		}
	}

	/**
	 * 修改资料
	 * @param int province_id 省份id
	 * @param int city_id 城市id
	 * @param int area_id 区域id
	 * @param string nickname 昵称
	 * @param string avatar 头像
	 * @param int sex 性别
	 * @param int birthday 生日时间戳
	 * @method POST
	 * @author   韩文博
	 */
	public function editProfile()
	{
		if( $this->verifyResourceRequest() !== true ){
			$this->send( Code::user_access_token_error );
		} else{
			if( isset( $this->post['province_id'] ) ){
				$data['province'] = $this->post['province'];
			}
			if( isset( $this->post['city_id'] ) ){
				$data['city_id'] = $this->post['city_id'];
			}
			if( isset( $this->post['area_id'] ) ){
				$data['area_id'] = $this->post['area_id'];
			}
			if( isset( $this->post['sex'] ) ){
				$data['sex'] = $this->post['sex'] ? 1 : 0;
			}
			if( isset( $this->post['nickname'] ) ){
				$data['nickname'] = $this->post['nickname'];
			}
			if( isset( $this->post['avatar'] ) ){
				$data['avatar'] = $this->post['avatar'];
			}
			if( isset( $this->post['birthday'] ) ){
				$data['birthday'] = $this->post['birthday'];
			}
			if( !empty( $data ) ){
				$user = $this->getRequestUser();
				\App\Model\User::init()->editUser( ['id' => $user['id']], $data );
				$this->send( Code::success );
			} else{
				$this->send( Code::error );
			}
		}
	}

	/**
	 * 绑定手机号
	 * @method POST
	 * @param string $phone       手机号
	 * @param string $password    密码
	 * @param string $verify_code 验证码
	 */
	public function bindPhone()
	{
		if( $this->verifyResourceRequest() !== true ){
			$this->send( Code::user_access_token_error );
		} else{
			$user             = $this->getRequestUser();
			$this->post['id'] = $user['id'];
			if( $this->validator( $this->post, 'Server/BindPhone.bindPhone' ) !== true ){
				$this->send( Code::error, [], $this->getValidator()->getError() );
			} else{

				$this->post['type'] = 'phone';

				try{
					$this->send( (new BindingLogic( (array)$this->post ))->binding() ? Code::success : Code::error );
				} catch( \App\Utils\Exception $e ){
					$this->send( $e->getCode() );
				}
			}
		}
	}

	/**
	 * 绑定微信
	 * @method POST
	 * @param string $wechat_openid
	 * @param string $wechat 包含字段：openid,nickname,sex,city,country,province,privilege(非必填),headimgurl,unionid（非必填）
	 */
	public function bindWechat()
	{
		if( $this->verifyResourceRequest() !== true ){
			$this->send( Code::user_access_token_error );
		} else{
			$user             = $this->getRequestUser();
			$this->post['id'] = $user['id'];
			if( $this->validator( $this->post, 'Server/User.bindWechat' ) !== true ){
				$this->send( Code::error, [], $this->getValidator()->getError() );
			} else{
				$this->post['type'] = 'wechat';

				try{
					$this->send( (new BindingLogic( (array)$this->post ))->binding() ? Code::success : Code::error );
				} catch( \App\Utils\Exception $e ){
					$this->send( $e->getCode() );
				}
			}
		}
	}

	/**
	 * 手机解绑微信
	 * 该账号必须绑定了手机，或者是手机注册的，并绑定了微信
	 * @method     POST
	 * @author   韩文博
	 */
	public function unbindWechat()
	{
		if( $this->verifyResourceRequest() !== true ){
			$this->send( Code::user_access_token_error );
		} else{
			$user           = $this->getRequestUser();
			$params         = [];
			$params['id']   = $user['id'];
			$params['type'] = 'wechat';
			try{
				$this->send( (new UntieLogic( $params ))->untie() ? Code::success : Code::error );
			} catch( \App\Utils\Exception $e ){
				$this->send( $e->getCode() );
			}
		}
	}

	/**
	 * 微信解绑手机
	 * 该账号必须绑定了微信，并绑定了手机
	 * @method     POST
	 * @author   韩文博
	 */
	public function unbindPhone()
	{
		if( $this->verifyResourceRequest() !== true ){
			$this->send( Code::user_access_token_error );
		} else{
			$user           = $this->getRequestUser();
			$params         = [];
			$params['id']   = $user['id'];
			$params['type'] = 'phone';
			try{
				$this->send( (new UntieLogic( $params ))->untie() ? Code::success : Code::error );
			} catch( \App\Utils\Exception $e ){
				$this->send( $e->getCode() );
			}
		}
	}

}

?>