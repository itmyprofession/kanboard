<?php

namespace Model;

use Core\Session;
use Core\Translator;

/**
 * Notification model
 *
 * @package  model
 * @author   Frederic Guillot
 */
class Notification extends Base
{
    /**
     * SQL table name
     *
     * @var string
     */
    const TABLE = 'user_has_notifications';

    /**
     * Get a list of people with notifications enabled
     *
     * @access public
     * @param  integer   $project_id      Project id
     * @param  array     $exclude_users   List of user_id to exclude
     * @return array
     */
    public function getUsersWithNotification($project_id, array $exclude_users = array())
    {
        if ($this->projectPermission->isEverybodyAllowed($project_id)) {

            return $this->db
                        ->table(User::TABLE)
                        ->columns(User::TABLE.'.id', User::TABLE.'.username', User::TABLE.'.name', User::TABLE.'.email', User::TABLE.'.language')
                        ->eq('notifications_enabled', '1')
                        ->neq('email', '')
                        ->notin(User::TABLE.'.id', $exclude_users)
                        ->findAll();
        }

        return $this->db
            ->table(ProjectPermission::TABLE)
            ->columns(User::TABLE.'.id', User::TABLE.'.username', User::TABLE.'.name', User::TABLE.'.email', User::TABLE.'.language')
            ->join(User::TABLE, 'id', 'user_id')
            ->eq('project_id', $project_id)
            ->eq('notifications_enabled', '1')
            ->neq('email', '')
            ->notin(User::TABLE.'.id', $exclude_users)
            ->findAll();
    }

    /**
     * Get the list of users to send the notification for a given project
     *
     * @access public
     * @param  integer   $project_id      Project id
     * @param  array     $exclude_users   List of user_id to exclude
     * @return array
     */
    public function getUsersList($project_id, array $exclude_users = array())
    {
        // Exclude the connected user
        if (Session::isOpen() && $this->userSession->isLogged()) {
            $exclude_users[] = $this->userSession->getId();
        }

        $users = $this->getUsersWithNotification($project_id, $exclude_users);

        foreach ($users as $index => $user) {

            $projects = $this->db->table(self::TABLE)
                                 ->eq('user_id', $user['id'])
                                 ->findAllByColumn('project_id');

            // The user have selected only some projects
            if (! empty($projects)) {

                // If the user didn't select this project we remove that guy from the list
                if (! in_array($project_id, $projects)) {
                    unset($users[$index]);
                }
            }
        }

        return $users;
    }

    /**
     * Send the email notifications
     *
     * @access public
     * @param  string    $template    Template name
     * @param  array     $users       List of users
     * @param  array     $data        Template data
     */
    public function sendEmails($template, array $users, array $data)
    {
        foreach ($users as $user) {

            // Use the user language otherwise use the application language (do not use the session language)
            if (! empty($user['language'])) {
                Translator::load($user['language']);
            }
            else {
                Translator::load($this->config->get('application_language', 'en_US'));
            }

            $this->emailClient->send(
                $user['email'],
                $user['name'] ?: $user['username'],
                $this->getMailSubject($template, $data),
                $this->getMailContent($template, $data)
            );
        }

        // Restore locales
        $this->config->setupTranslations();
    }

    /**
     * Get the mail subject for a given label
     *
     * @access private
     * @param  string    $label       Label
     * @param  array     $data        Template data
     */
    private function getStandardMailSubject($label, array $data)
    {
        return sprintf('[%s][%s] %s (#%d)', $data['task']['project_name'], $label, $data['task']['title'], $data['task']['id']);
    }

    /**
     * Get the mail subject for a given template name
     *
     * @access public
     * @param  string    $template    Template name
     * @param  array     $data        Template data
     */
    public function getMailSubject($template, array $data)
    {
        switch ($template) {
            case 'file_creation':
                $subject = $this->getStandardMailSubject(e('New attachment'), $data);
                break;
            case 'comment_creation':
                $subject = $this->getStandardMailSubject(e('New comment'), $data);
                break;
            case 'comment_update':
                $subject = $this->getStandardMailSubject(e('Comment updated'), $data);
                break;
            case 'subtask_creation':
                $subject = $this->getStandardMailSubject(e('New subtask'), $data);
                break;
            case 'subtask_update':
                $subject = $this->getStandardMailSubject(e('Subtask updated'), $data);
                break;
            case 'task_creation':
                $subject = $this->getStandardMailSubject(e('New task'), $data);
                break;
            case 'task_update':
                $subject = $this->getStandardMailSubject(e('Task updated'), $data);
                break;
            case 'task_close':
                $subject = $this->getStandardMailSubject(e('Task closed'), $data);
                break;
            case 'task_open':
                $subject = $this->getStandardMailSubject(e('Task opened'), $data);
                break;
            case 'task_move_column':
                $subject = $this->getStandardMailSubject(e('Column Change'), $data);
                break;
            case 'task_move_position':
                $subject = $this->getStandardMailSubject(e('Position Change'), $data);
                break;
            case 'task_assignee_change':
                $subject = $this->getStandardMailSubject(e('Assignee Change'), $data);
                break;
            case 'task_due':
                $subject = e('[%s][Due tasks]', $data['project']);
                break;
            default:
                $subject = e('[Kanboard] Notification');
        }

        return $subject;
    }

    /**
     * Get the mail content for a given template name
     *
     * @access public
     * @param  string    $template    Template name
     * @param  array     $data        Template data
     */
    public function getMailContent($template, array $data)
    {
        return $this->template->render(
            'notification/'.$template,
            $data + array('application_url' => $this->config->get('application_url'))
        );
    }

    /**
     * Save settings for the given user
     *
     * @access public
     * @param  integer   $user_id   User id
     * @param  array     $values    Form values
     */
    public function saveSettings($user_id, array $values)
    {
        // Delete all selected projects
        $this->db->table(self::TABLE)->eq('user_id', $user_id)->remove();

        if (isset($values['notifications_enabled']) && $values['notifications_enabled'] == 1) {

            // Activate notifications
            $this->db->table(User::TABLE)->eq('id', $user_id)->update(array(
                'notifications_enabled' => '1'
            ));

            // Save selected projects
            if (! empty($values['projects'])) {

                foreach ($values['projects'] as $project_id => $checkbox_value) {
                    $this->db->table(self::TABLE)->insert(array(
                        'user_id' => $user_id,
                        'project_id' => $project_id,
                    ));
                }
            }
        }
        else {

            // Disable notifications
            $this->db->table(User::TABLE)->eq('id', $user_id)->update(array(
                'notifications_enabled' => '0'
            ));
        }
    }

    /**
     * Read user settings to display the form
     *
     * @access public
     * @param  integer   $user_id   User id
     * @return array
     */
    public function readSettings($user_id)
    {
        $values = array();
        $values['notifications_enabled'] = $this->db->table(User::TABLE)->eq('id', $user_id)->findOneColumn('notifications_enabled');

        $projects = $this->db->table(self::TABLE)->eq('user_id', $user_id)->findAllByColumn('project_id');

        foreach ($projects as $project_id) {
            $values['project_'.$project_id] = true;
        }

        return $values;
    }
}
