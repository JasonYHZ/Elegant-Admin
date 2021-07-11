<?php
declare(strict_types=1);

namespace App\Security;

use App\Exceptions\NoPermissionException;
use App\Exceptions\UnauthorizedException;
use App\Repository\UserRepository;
use App\Task\GetUserInfoCacheTask;
use App\Utils\TokenUtils\TokenUtils;
use ReflectionException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiKeyAuthenticator extends AbstractAuthenticator
{
    private TokenUtils $tokenUtils;

    private GetUserInfoCacheTask $getUserInfoCacheTask;

    private UserRepository $userRepository;

    /**
     * ApiKeyAuthenticator constructor.
     * @param TokenUtils $tokenUtils
     * @param GetUserInfoCacheTask $getUserInfoCacheTask
     * @param UserRepository $userRepository
     */
    public function __construct(
        TokenUtils $tokenUtils,
        GetUserInfoCacheTask $getUserInfoCacheTask,
        UserRepository $userRepository
    ) {
        $this->tokenUtils = $tokenUtils;
        $this->getUserInfoCacheTask = $getUserInfoCacheTask;
        $this->userRepository = $userRepository;
    }

    /**
     * 身份验证器是否支持给定的请求？
     * 如果返回 false，则将跳过验证器。
     * 返回 null 意味着在访问令牌存储时可以延迟调用 authenticate()。
     */
    public function supports(Request $request): ?bool
    {
        // 路由额外参数
        if ($request->attributes->get('anonymous')) {
            return false;
        }
        return true;
    }

    /**
     * 为当前请求创建通行证。
     * 通行证包含用户、凭证和任何必须由 Symfony 安全系统检查的附加信息。
     * 例如，登录表单身份验证器可能会返回包含用户、提供的密码和 CSRF 令牌值的通行证。
     * 如果出现错误，您可以在此方法中抛出任何 AuthenticationException （例如，无法找到用户时的 UserNotFoundException）。
     * @param Request $request
     * @return PassportInterface
     * @throws ReflectionException
     */
    public function authenticate(Request $request): PassportInterface
    {
        $credentials = $request->headers->get('API-TOKEN');
        if (!$credentials) {
            throw new UnauthorizedException();
        }

        $token = $this->tokenUtils->validateToken($credentials);
        $userCache = $this->getUserInfoCacheTask->run($token->id);
        if (is_null($userCache) || !in_array($credentials, $userCache->tokens)) {
            throw new UnauthorizedException();
        }
        if ($userCache->status === 0) {
            throw new NoPermissionException('账号异常');
        }

        return new SelfValidatingPassport(new UserBadge($credentials, function () use ($token) {
            $user = $this->userRepository->findByIdField($token->id);
            if (!$user) {
                throw new UnauthorizedException();
            }
            return $user;
        }));
    }

    /**
     * 为给定用户创建经过身份验证的令牌。
     * 如果您不关心使用哪个令牌类，或者不真正了解“令牌”是什么，
     * 您可以通过从您的身份验证器扩展 AbstractAuthenticator 类来跳过此方法。
     *
     * @param PassportInterface $passport The passport returned from authenticate()
     * @see AbstractAuthenticator
     *
     */
    public function createAuthenticatedToken(PassportInterface $passport, string $firewallName): TokenInterface
    {
        return parent::createAuthenticatedToken($passport, $firewallName);
    }

    /**
     * 在身份验证执行并成功时调用！
     * 这应该返回发送回给用户的响应，就像他们访问的最后一个页面的重定向响应一样。
     * 如果返回 null，则当前请求将继续，并且用户将通过身份验证。
     * 例如，对于 API，这是合理的。
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * 在执行身份验证但失败时调用（例如，用户名密码错误）。
     * 这应该返回发送回给用户的响应，如对登录页面的重定向响应或 403 响应。
     * 如果您返回 null，则请求将继续，但不会对用户进行身份验证。
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
