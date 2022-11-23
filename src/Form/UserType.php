<?php

declare(strict_types=1);

namespace Bolt\Form;

use Bolt\Collection\DeepCollection;
use Bolt\Configuration\Config;
use Bolt\Entity\Content;
use Bolt\Entity\User;
use Bolt\Enum\UserStatus;
use Bolt\Repository\ContentRepository;
use Bolt\Utils\LocaleHelper;
use Doctrine\ORM\Cache\Region;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;

class UserType extends AbstractType
{
    /** @var LocaleHelper */
    private $localeHelper;

    /** @var Environment */
    private $twig;

    /** @var DeepCollection */
    private $avatarConfig;

    /** @var ContentRepository */
    private $contentRepository;

    /** @var Security */
    private $security;
    /**
     * @var RequestStack
     */
    private $requestStack;

    private $managerRegistry;

    public function __construct(LocaleHelper $localeHelper, Environment $twig, Config $config, Security $security, ContentRepository $contentRepository, RequestStack $requestStack, ManagerRegistry $managerRegistry)
    {
        $this->localeHelper = $localeHelper;
        $this->twig = $twig;

        /** @var DeepCollection $config */
        $config = $config->get('general');
        $this->avatarConfig = $config->get('user_avatar');
        $this->contentRepository = $contentRepository;


        $this->security = $security;
        $this->requestStack = $requestStack;
        $this->managerRegistry = $managerRegistry;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Create custom role options array
        $roleOptions = [];
        $custom_roles = $options['roles'];
        foreach ($custom_roles as $roleName => $roleValue) {
            // For some reason these select arrays are built like
            // array-key => Label
            // array-value => Key which is used to save in the DB (and used for validation)
            // (at the moment $roleName and $roleValue are almost always the same, but this could change in the future)
            $roleOptions[$roleName] = $roleValue;
        }

        // Create custom location options array
        $locationOptions = [];
        $custom_locations = $this->localeHelper->getLocales($this->twig, null, true)->all();
        foreach ($custom_locations as $location) {
            $data = $location->all();
            // Same strange array structure as above
            $description = sprintf('%s %s (%s, %s)', $data['emoji'], $data['name'], $data['localizedname'], $data['code']);
            $locationOptions[$description] = $data['code'];
        }

        /*
         * These are the field that are available to all these actions
         *
         * - /bolt/user-edit/add
         * - /bolt/user-edit/<ID>
         * - /bolt/profile-edit
         */
        $builder
            ->add('username', TextType::class, [
                'required' => $options['require_username'],
                // Always disable this field if it is not required aka not on add user page
                'disabled' => ! $options['require_username'],
            ])
            ->add('displayName', TextType::class)
            ->add('plainPassword', PasswordType::class, [
                // We need that, otherwise the suggested password won't be auto filled in
                'always_empty' => false,
                'attr' => [
                    'suggested_password' => $options['suggested_password'],
                ],
                'required' => $options['require_password'],
                'empty_data' => '',
            ])
            ->add('email', EmailType::class)
            ->add('locale', ChoiceType::class, [
                'choices' => $locationOptions,
                'empty_data' => $options['default_locale'],
                'attr' => [
                    'default_locale' => $options['default_locale'],
                ],
            ])
            ->add('avatar', TextType::class, [
                'required' => false,
                'attr' => [
                    'upload_path' => $this->avatarConfig->get('upload_path'),
                    'extensions_allowed' => $this->avatarConfig->get('extensions_allowed'),
                ],
            ])
        ;

        /*
         * Add Roles and Status if the form is used to add a new user or edit an existing one
         * AND the current user has add OR edit rights.
         * (check if either editing or adding is allowed is done in the controller)
         *
         * Note that the profile edit screen never should show these options.
         */
        if ($options['is_profile_edit'] === false &&
            ($this->security->isGranted('user:add') || $this->security->isGranted('user:delete'))) {
            $builder
                ->add('roles', ChoiceType::class, [
                    'choices' => $roleOptions,
                    'multiple' => true,
                ])
                ->add('status', ChoiceType::class, [
                    'choices' => UserStatus::all(),
                ]);
        }

//            ->add('lastseenAt')
//            ->add('lastIp')
//            ->add('backendTheme')
//            ->add('userAuthToken')

        $builder
            ->add('region', EntityType::class, [
                'class' => Content::class,
                'choice_label' => function (Content $region) {
                    foreach ($region->getFields() as $field) {
                        if ($field->getName() === 'title') {
                            return $field->getValue()[0];
                        }
                    }
                    return null;
                },
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('r')
                        ->select('r,f')
                        ->innerJoin('r.fields', 'f')
                        ->andWhere('r.contentType=:regions')
                        ->setParameter('regions', "regions");
                },

            ]);

        $countries = [];
        $em = $this->managerRegistry->getManager();
        foreach ($options['data']->getCountries() as $country) {
            $countries[] = $em->getReference('Bolt\Entity\Content', $country->getId());
        }

        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use($options, $countries) {
                $form = $event->getForm();

                $data = $event->getData();
                if (!$data) {
                    return;
                }

                $form->add('countries', EntityType::class, [
                    'multiple' => true,
                    'required' => false,
                    'class' => Content::class,
                    'choice_label' => function (Content $country) {
                        foreach ($country->getFields() as $field) {
                            if ($field->getName() === 'title') {
                                return $field->getValue()[0];
                            }
                        }
                        return null;
                    },
                    'data' => $countries,
                    'query_builder' => function (EntityRepository $er) use($options) {
                    $region = $this->requestStack->getCurrentRequest()->request->has('user') ? $this->requestStack->getCurrentRequest()->request->get('user')['region'] : $options['data']?->getRegion()?->getId();
                        return $er->createQueryBuilder('c')
                            ->select('c,f')
                            ->innerJoin('c.fields', 'f')
                            ->innerJoin('c.relationsFromThisContent', 'rf')
                            ->andWhere('c.contentType=:countries')
                            ->andWhere('rf.toContent=:region')
                            ->setParameter('countries', "countries")
                            ->setParameter('region',  $region)
                            ;
                    },
                ]);
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'suggested_password' => '',
            'roles' => '',
            'require_username' => '',
            'require_password' => '',
            'default_locale' => '',
            'is_profile_edit' => false,
            'validation_groups' => function (FormInterface $form) {
                /** @var User $entity */
                $entity = $form->getData();

                $validation_group = 'edit_user';
                if ($entity->isNewUser()) {
                    $validation_group = 'add_user';
                } elseif ($entity->getPlainPassword() === '') {
                    $validation_group = 'edit_user_without_pw';
                }

                return $validation_group;
            },
        ]);
    }
}
