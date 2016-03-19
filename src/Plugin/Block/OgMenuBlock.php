<?php

/**
 * @file
 * Contains \Drupal\system\Plugin\Block\SystemMenuBlock.
 */

namespace Drupal\og_menu\Plugin\Block;

use Drupal\Component\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\og\OgGroupAudienceHelper;
use Drupal\og_menu\OgMenuInstanceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a generic Menu block.
 *
 * @Block(
 *   id = "ogmenu_block",
 *   admin_label = @Translation("OG Menu"),
 *   category = @Translation("OG Menus"),
 *   deriver = "Drupal\og_menu\Plugin\Derivative\OgMenuBlock",
 *   context = {
 *     "og" = @ContextDefinition("og", label = @Translation("Organic group"))
 *   }
 * )
 */
class OgMenuBlock extends BlockBase implements ContainerFactoryPluginInterface, ContextAwarePluginInterface {
  protected $menu_name;

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuTree;

  /**
   * The active menu trail service.
   *
   * @var \Drupal\Core\Menu\MenuActiveTrailInterface
   */
  protected $menuActiveTrail;

  /**
   * Constructs a new SystemMenuBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_tree
   *   The menu tree service.
   * @param \Drupal\Core\Menu\MenuActiveTrailInterface $menu_active_trail
   *   The active menu trail service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MenuLinkTreeInterface $menu_tree, MenuActiveTrailInterface $menu_active_trail, AccessManagerInterface $access_manager, AccountInterface $account, EntityManagerInterface $entity_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->menuTree = $menu_tree;
    $this->menuActiveTrail = $menu_active_trail;
    $this->accessManager = $access_manager;
    $this->account = $account;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('menu.link_tree'),
      $container->get('menu.active_trail'),
      $container->get('access_manager'),
      $container->get('current_user'),
      $container->get('entity.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->configuration;

    $defaults = $this->defaultConfiguration();
    $form['menu_levels'] = array(
      '#type' => 'details',
      '#title' => $this->t('Menu levels'),
      // Open if not set to defaults.
      '#open' => $defaults['level'] !== $config['level'] || $defaults['depth'] !== $config['depth'],
      '#process' => [[get_class(), 'processMenuLevelParents']],
    );

    $options = range(0, $this->menuTree->maxDepth());
    unset($options[0]);

    $form['menu_levels']['level'] = array(
      '#type' => 'select',
      '#title' => $this->t('Initial menu level'),
      '#default_value' => $config['level'],
      '#options' => $options,
      '#description' => $this->t('The menu will only be visible if the menu item for the current page is at or below the selected starting level. Select level 1 to always keep this menu visible.'),
      '#required' => TRUE,
    );

    $options[0] = $this->t('Unlimited');

    $form['menu_levels']['depth'] = array(
      '#type' => 'select',
      '#title' => $this->t('Maximum number of menu levels to display'),
      '#default_value' => $config['depth'],
      '#options' => $options,
      '#description' => $this->t('The maximum number of menu levels to show, starting from the initial menu level. For example: with an initial level 2 and a maximum number of 3, menu levels 2, 3 and 4 can be displayed.'),
      '#required' => TRUE,
    );

    return $form;
  }

  /**
   * Form API callback: Processes the menu_levels field element.
   *
   * Adjusts the #parents of menu_levels to save its children at the top level.
   */
  public static function processMenuLevelParents(&$element, FormStateInterface $form_state, &$complete_form) {
    array_pop($element['#parents']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['level'] = $form_state->getValue('level');
    $this->configuration['depth'] = $form_state->getValue('depth');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $menu_name = $this->getMenuName();
    $parameters = $this->menuTree->getCurrentRouteMenuTreeParameters($menu_name);

    // Adjust the menu tree parameters based on the block's configuration.
    $level = $this->configuration['level'];
    $depth = $this->configuration['depth'];
    $parameters->setMinDepth($level);
    // When the depth is configured to zero, there is no depth limit. When depth
    // is non-zero, it indicates the number of levels that must be displayed.
    // Hence this is a relative depth that we must convert to an actual
    // (absolute) depth, that may never exceed the maximum depth.
    if ($depth > 0) {
      $parameters->setMaxDepth(min($level + $depth - 1, $this->menuTree->maxDepth()));
    }

    $tree = $this->menuTree->load($menu_name, $parameters);
    $manipulators = array(
      array('callable' => 'menu.default_tree_manipulators:checkAccess'),
      array('callable' => 'menu.default_tree_manipulators:generateIndexAndSort'),
    );
    $tree = $this->menuTree->transform($tree, $manipulators);
    $build = $this->menuTree->build($tree);
    if (!$tree) {
      $route_name = 'ogmenu_instance.add_page';
      $route_parameters = [];
      $access = $this->accessManager->checkNamedRoute($route_name, $route_parameters, $this->account, TRUE);
      /** @var \Drupal\Core\Entity\EntityInterface $og_entity */
      $og_entity = $this->getContext('og')->getContextData()->getValue();
      $build['create'] = array(
        '#theme' => 'menu_local_action',
        '#link' => array(
          'title' => $this->t('Add menu'),
          'url' => Url::fromRoute('ogmenu_instance.create', [
            'ogmenu' => $this->getDerivativeId(),
            'og_group_entity_type' => $og_entity->getEntityTypeId(),
            // @todo Think about how to get rid of this hack for rdf entities:
            'og_group' => str_replace('/', '\\', $og_entity->id())
          ]),
        ),
        '#access' => $access,
      );
    }
    $menu_instance = $this->getOgMenuInstance();
    if ($menu_instance instanceof OgMenuInstanceInterface) {
      $build['#contextual_links']['ogmenu'] = array(
        'route_parameters' => array(
          'ogmenu_instance' => $menu_instance->id(),
        ),
      );
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'level' => 1,
      'depth' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // Even when the menu block renders to the empty string for a user, we want
    // the cache tag for this menu to be set: whenever the menu is changed, this
    // menu block must also be re-rendered for that user, because maybe a menu
    // link that is accessible for that user has been added.
    $cache_tags = parent::getCacheTags();
    $menu_instance = $this->getOgMenuInstance();
    if ($menu_instance instanceof OgMenuInstanceInterface) {
      $cache_tags[] = 'config:system.menu:ogmenu-' . $menu_instance->id();
    }

    return $cache_tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // ::build() uses MenuLinkTreeInterface::getCurrentRouteMenuTreeParameters()
    // to generate menu tree parameters, and those take the active menu trail
    // into account. Therefore, we must vary the rendered menu by the active
    // trail of the rendered menu.
    // Additional cache contexts, e.g. those that determine link text or
    // accessibility of a menu, will be bubbled automatically.
    $menu_name = 'ogmenu-' . $this->getDerivativeId();
    return Cache::mergeContexts(parent::getCacheContexts(), ['route.menu_active_trails:' . $menu_name]);
  }

  public function getOgMenuInstance() {
    $entity = $this->getContext('og')->getContextData()->getValue();
    $entity_id = $entity->id();
    $instances = $this->entityManager->getStorage('ogmenu_instance')->loadByProperties([
      'type' => $this->getDerivativeId(),
      OgGroupAudienceHelper::DEFAULT_FIELD => $entity_id,
    ]);
    if ($instances) {
      return array_pop($instances);
    }
    return FALSE;
  }

  public function getMenuName() {
    if (isset($this->menu_name)) {
      return $this->menu_name;
    }
    $instance = $this->getOgMenuInstance();
    if ($instance) {
      $this->menu_name = 'ogmenu-' . $instance->id();
    }
    return $this->menu_name;
  }

}
