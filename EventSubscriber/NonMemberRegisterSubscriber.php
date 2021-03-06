<?php

/*
 * Copyright (C) 2019 Akira Kurozumi <info@a-zumi.net>.
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301  USA
 */

namespace Customize\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Event\TemplateEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Customize\Form\Type\NonMemberRegisterType;
use Eccube\Repository\CustomerRepository;
use Eccube\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\ControllerTrait;
use Eccube\Service\MailService;
use Eccube\Repository\BaseInfoRepository;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Eccube\Service\OrderHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @author Akira Kurozumi <info@a-zumi.net>
 */
class NonMemberRegisterSubscriber implements EventSubscriberInterface
{
    use ControllerTrait;
    
    /**
     * @var ContainerInterface
     */
    private $container;
    
    /**
     * @var CustomerRepository 
     */
    private $customerRepository;
    
    /**
     * @var CartService
     */
    private $cartService;
    
    /**
     * @var MailService
     */
    private $mailService;

    /**
     * @var BaseInfo
     */
    private $BaseInfo;
    
    /**
     * @var EncoderFactoryInterface
     */
    private $encoderFactory;
    
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;
    
    /**
     * @var OrderHelper
     */
    private $orderHelper;
    
    public function __construct(
            ContainerInterface $container, 
            CustomerRepository $customerRepository, 
            CartService $cartService,
            MailService $mailService,
            BaseInfoRepository $baseInfoRepository,
            EncoderFactoryInterface $encoderFactory,
            EntityManagerInterface $entityManager,
            OrderHelper $orderHelper
    ) {
        $this->container = $container;
        $this->customerRepository = $customerRepository;
        $this->cartService = $cartService;
        $this->mailService = $mailService;
        $this->BaseInfo = $baseInfoRepository->get();
        $this->encoderFactory = $encoderFactory;
        $this->entityManager = $entityManager;
        $this->orderHelper = $orderHelper;
    }
    
    public function onFrontShoppingCompleteInitialize(EventArgs $event)
    {
        $Order = $event->getArgument("Order");
        
        $Customer = $this->customerRepository->findOneBy(["email" => $Order->getEmail()]);
        
        // メールアドレスが登録されていたら停止
        if($Customer) {
            return;
        }
        
        $request = $event->getRequest();
        
        $builder = $this->container->get('form.factory')->createBuilder(NonMemberRegisterType::class, [], ["Order" => $Order]);
        $form = $builder->getForm();
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            log_info('会員登録確認開始');
            
            $password = $form->get("password")->getData();
            
            $Customer = $this->customerRepository->newCustomer();
            
            $Customer
                ->setName01($Order->getName01())
                ->setName02($Order->getName02())
                ->setKana01($Order->getKana01())
                ->setKana02($Order->getKana02())
                ->setCompanyName($Order->getCompanyName())
                ->setEmail($Order->getEmail())
                ->setPhonenumber($Order->getPhoneNumber())
                ->setPostalcode($Order->getPostalCode())
                ->setPref($Order->getPref())
                ->setAddr01($Order->getAddr01())
                ->setAddr02($Order->getAddr02())
                ->setPassword($password);
            
            // パスワードを暗号化
            $encoder = $this->encoderFactory->getEncoder($Customer);
            $salt = $encoder->createSalt();
            $password = $encoder->encodePassword($Customer->getPassword(), $salt);
            $secretKey = $this->customerRepository->getUniqueSecretKey();

            // 暗号化したパスワードをセット
            $Customer
                ->setSalt($salt)
                ->setPassword($password)
                ->setSecretKey($secretKey)
                ->setPoint(0);
            
            $this->entityManager->persist($Customer);
            $this->entityManager->flush();

            log_info('会員登録完了');
            
            log_info('[注文完了] 購入フローのセッションをクリアします. ');
            // 会員登録が完了したらセッション削除
            $this->orderHelper->removeSession();
            
            $activateUrl = $this->generateUrl('entry_activate', ['secret_key' => $Customer->getSecretKey()], UrlGeneratorInterface::ABSOLUTE_URL);

            $activateFlg = $this->BaseInfo->isOptionCustomerActivate();

            // 仮会員設定が有効な場合は、確認メールを送信し完了画面表示.
            if ($activateFlg) {
                // メール送信
                $this->mailService->sendCustomerConfirmMail($Customer, $activateUrl);

                log_info('仮会員登録完了画面へリダイレクト');
                
                $event->setResponse($this->redirectToRoute('entry_complete'));
                                
            // 仮会員設定が無効な場合は認証URLへ遷移させ、会員登録を完了させる.
            } else {
                log_info('本会員登録画面へリダイレクト');
                
                $event->setResponse($this->redirect($activateUrl));
            }
        }else{
            $hasNextCart = !empty($this->cartService->getCarts());

            log_info('[注文完了] 注文完了画面を表示しました. ', [$hasNextCart]);

            $event->setResponse($this->render("Shopping/complete.register.twig", [
                'Order' => $Order,
                'hasNextCart' => $hasNextCart,
                'form' => $form->createView()
            ]));            
        }
    }
    
    public static function getSubscribedEvents()
    {
        return [
            EccubeEvents::FRONT_SHOPPING_COMPLETE_INITIALIZE => 'onFrontShoppingCompleteInitialize',
        ];
    }
}
