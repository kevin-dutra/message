<?php

namespace Drupal\Tests\message\Functional;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Language\Language;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\message\Entity\Message;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Test the Message delete on entity delete functionality.
 *
 * @group Message
 */
class MessageEntityDelete extends MessageTestBase {

  /**
   * Taxonomy vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary
   */
  protected $vocabulary;

  /**
   * The Node Type.
   *
   * @var \Drupal\node\Entity\NodeType
   */
  protected $nodeType;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'message',
    'entity_reference',
    'node',
    'taxonomy',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Set config.
    $entities = ['node', 'taxonomy_term', 'user'];
    $this->configSet('delete_on_entity_delete', $entities);

    // Set config.
    $this->createMessageTemplate('dummy_message', 'Dummy message', 'This is a dummy message text', ['Dummy message template.']);

    // Create a vocabulary.
    $this->vocabulary = entity_create('taxonomy_vocabulary', [
      'name' => $this->randomMachineName(),
      'description' => $this->randomMachineName(),
      'vid' => Unicode::strtolower($this->randomMachineName()),
      'langcode' => Language::LANGCODE_NOT_SPECIFIED,
      'weight' => mt_rand(0, 10),
    ]);
    $this->vocabulary->save();

    $this->createTermReferenceField(TRUE, 'field_term_references');
    $this->createEntityReferenceField(TRUE, 'field_node_references');

    $this->createTermReferenceField(FALSE, 'field_term_reference');
    $this->createEntityReferenceField(FALSE, 'field_node_reference');

    $this->createEntityReferenceField(FALSE, 'field_user_reference', 'user');

    $this->nodeType = $this->drupalCreateContentType();

    for ($i = 0; $i <= 5; $i++) {
      entity_create('node', [
        'type' => $this->nodeType->id(),
        'title' => 'Node ' . $i,
      ])->save();

      entity_create('taxonomy_term', [
        'vid' => $this->vocabulary->id(),
        'name' => 'term ' . $i,
      ])->save();
    }
  }

  /**
   * Create a term reference field.
   *
   * @param bool $multiple
   *   Determine of the field should be multiple.
   * @param string $name
   *   The name of the field.
   */
  private function createTermReferenceField($multiple, $name) {
    // Create a term reference field.
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'message',
      'type' => 'entity_reference',
      'cardinality' => $multiple ? FieldStorageConfig::CARDINALITY_UNLIMITED : 1,
      'settings' => [
        'target_type' => 'taxonomy_term',
        'allowed_values' => [
          [
            'vocabulary' => $this->vocabulary->id(),
            'parent' => 0,
          ],
        ],
      ],
    ])->save();

    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'message',
      'bundle' => 'dummy_message',
      'required' => TRUE,
    ])->save();
  }

  /**
   * Create a multiple entity reference field.
   *
   * @param bool $multiple
   *   Determine of the field should be multiple.
   * @param string $name
   *   The name of the field.
   * @param string $target_type
   *   The target type. Default to node.
   */
  private function createEntityReferenceField($multiple, $name, $target_type = 'node') {

    // Create a term reference field.
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'message',
      'translatable' => FALSE,
      'entity_types' => [],
      'settings' => [
        'target_type' => $target_type,
      ],
      'type' => 'entity_reference',
      'cardinality' => $multiple ? FieldStorageConfig::CARDINALITY_UNLIMITED : 1,
    ])->save();

    FieldConfig::create([
      'label' => 'Entity reference field',
      'field_name' => $name,
      'entity_type' => 'message',
      'bundle' => 'dummy_message',
      'settings' => [
        'handler' => 'default',
        'handler_settings' => [
          'target_bundles' => [
            $target_type,
          ],
          'auto_create' => TRUE,
        ],
      ],
    ])->save();
  }

  /**
   * Test deletion of a message after its referenced entities have been deleted.
   */
  public function testReferencedEntitiesDelete() {
    /** @var \Drupal\Core\Queue\QueueInterface $delete_queue */
    $delete_queue = $this->container->get('queue')->get('message_delete');
    /** @var \Drupal\Core\Queue\QueueInterface $check_delete_queue */
    $check_delete_queue = $this->container->get('queue')->get('message_check_delete');
    /** @var \Drupal\Core\CronInterface $cron */
    $cron = $this->container->get('cron');

    // Testing nodes reference.
    $message = Message::create(['template' => 'dummy_message']);
    $message->set('field_node_references', [1, 2]);
    $message->save();

    Node::load(1)->delete();
    $cron->run();
    $this->assertTrue(Message::load($message->id()), 'Message exists after deleting one of two referenced nodes.');
    Node::load(2)->delete();
    $cron->run();
    $this->assertFalse(Message::load($message->id()), 'Message deleted after deleting all referenced nodes.');

    // Test terms references.
    $message = Message::create(['template' => 'dummy_message']);
    $message->set('field_term_references', [1, 2]);
    $message->save();

    Term::load(1)->delete();
    $cron->run();
    $this->assertTrue(Message::load($message->id()), 'Message exists after deleting one of two referenced terms.');
    Term::load(2)->delete();
    $cron->run();
    $this->assertFalse(Message::load($message->id()), 'Message deleted after deleting all referenced terms.');

    // Test term references.
    $term = Term::load(3);
    $message = Message::create(['template' => 'dummy_message']);
    $message->set('field_term_reference', $term);
    $message->save();

    $term->delete();
    $cron->run();
    $this->assertFalse(Message::load($message->id()), 'Message deleted after deleting single referenced term.');

    // Test node reference.
    $message = Message::create(['template' => 'dummy_message']);
    $message->set('field_node_reference', 3);
    $message->save();

    Node::load(3)->delete();
    $cron->run();
    $this->assertFalse(Message::load($message->id()), 'Message deleted after deleting single referenced node.');

    // Testing when a message referenced to terms and term.
    $message = Message::create(['template' => 'dummy_message']);
    $message->set('field_term_references', [4, 5]);
    $message->set('field_term_reference', 4);
    $message->save();
    Term::load(4)->delete();
    $cron->run();

    $this->assertFalse(Message::load($message->id()), 'Message deleted after deleting single referenced term while another the message still references other term in another field.');

    // Test user reference.
    $account = $this->drupalCreateUser();
    $message = Message::create(['template' => 'dummy_message']);
    $message->set('field_user_reference', $account->id());
    $message->save();

    $account->delete();
    $cron->run();
    $this->assertFalse(Message::load($message->id()), 'Message deleted after deleting single referenced user.');
  }

}
