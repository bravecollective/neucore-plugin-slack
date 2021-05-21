<?php

namespace Brave\Neucore\Plugin\Slack;

use Neucore\Plugin\CoreCharacter;
use Neucore\Plugin\CoreGroup;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceAccountData;
use Neucore\Plugin\ServiceInterface;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

/** @noinspection PhpUnused */
class Service implements ServiceInterface
{
    private const STATUS_ACTIVE = 'Active';

    #private const STATUS_TERMINATED = 'Terminated';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PDO|null
     */
    private $pdo;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param CoreCharacter[] $characters
     * @param CoreGroup[] $groups
     * @return ServiceAccountData[]
     * @throws Exception
     */
    public function getAccounts(array $characters, array $groups): array
    {
        if (count($characters) === 0) {
            return [];
        }

        $this->dbConnect();

        // fetch accounts
        $characterIds = array_map(function (CoreCharacter $character) {
            return $character->id;
        }, $characters);
        $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT character_id, email, invited_at, slack_id, account_status AS status
            FROM invite 
            WHERE character_id IN ($placeholders)"
        );
        try {
            $stmt->execute($characterIds);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // build result
        $result = [];
        foreach ($rows as $row) {
            $status =  ServiceAccountData::STATUS_UNKNOWN;
            if ($row['status'] === 'Active' && $row['slack_id'] !== null) {
                $status = ServiceAccountData::STATUS_ACTIVE;
            } elseif ($row['invited_at'] > $this->getInviteWaitTime()) {
                $status = ServiceAccountData::STATUS_PENDING;
            } elseif ($row['status'] === 'Terminated' && $row['slack_id'] !== null) {
                $status = ServiceAccountData::STATUS_DEACTIVATED;
            }
            $result[] = new ServiceAccountData((int)$row['character_id'], null, null, $row['email'], $status);
        }

        return $result;
    }

    /**
     * @param CoreGroup[] $groups
     * @param int[] $allCharacterIds
     * @throws Exception
     */
    public function register(
        CoreCharacter $character,
        array $groups,
        string $emailAddress,
        array $allCharacterIds
    ): ServiceAccountData {
        if ($emailAddress === '') {
            throw new Exception(self::ERROR_MISSING_EMAIL);
        }

        $this->dbConnect();

        try {
            $emailOk = $this->emailAssignedToSamePlayer($emailAddress, $allCharacterIds);
        } catch(PDOException $e) {
            throw new Exception();
        }
        if (!$emailOk) {
            throw new Exception(self::ERROR_EMAIL_MISMATCH);
        }

        // add or update account
        $stmt = $this->pdo->prepare('SELECT email, email_history, invited_at FROM invite WHERE character_id = :id');
        try {
            $stmt->execute([':id' => $character->id]);
        } catch(PDOException $e) {
            throw new Exception();
        }
        if (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($row['invited_at'] > $this->getInviteWaitTime()) {
                throw new Exception(self::ERROR_INVITE_WAIT);
            }
            $update = $this->pdo->prepare(
                'UPDATE invite 
                SET email = :email, invited_at = :invited_at, email_history = :email_history 
                WHERE character_id = :character_id'
            );
            $update->bindValue(':email', $emailAddress);
            $update->bindValue(':invited_at', time());
            $update->bindValue(':email_history', $row['email_history'] . ", " . $row['email']);
            $update->bindValue(':character_id', $character->id);
            try {
                $update->execute();
            } catch(PDOException $e) {
                throw new Exception();
            }
        } else {
            $insert = $this->pdo->prepare(
                'INSERT INTO invite (character_id, character_name, email, invited_at) 
                VALUES (:character_id, :character_name, :email, :invited_at)'
            );
            $insert->bindValue(':character_id', $character->id);
            $insert->bindValue(':character_name', $character->name);
            $insert->bindValue(':email', $emailAddress);
            $insert->bindValue(':invited_at', time());
            try {
                $insert->execute();
            } catch(PDOException $e) {
                throw new Exception();
            }
        }

        $this->sendSlack("$character->name <$emailAddress>");

        return new ServiceAccountData($character->id, null, null, $emailAddress);
    }

    public function updateAccount(CoreCharacter $character, array $groups): void
    {
        throw new Exception();
    }

    public function resetPassword(int $characterId): string
    {
        throw new Exception();
    }

    public function getAllAccounts(): array
    {
        throw new Exception();
    }

    private function getInviteWaitTime(): int
    {
        return time() - (60 * 60 * 24);
    }

    /**
     * @throws PDOException
     */
    private function emailAssignedToSamePlayer(string $emailAddress, array $otherCharacterIds): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT character_id FROM invite WHERE email = :email AND account_status = :account_status'
        );
        try {
            $stmt->execute([':email' => $emailAddress, ':account_status' => self::STATUS_ACTIVE]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw $e;
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) === 0) {
            return true;
        }

        foreach ($rows as $row) {
            if (in_array($row["character_id"], $otherCharacterIds)) {
                return true;
            }
        }

        return false;
    }

    private function sendSlack($text)
    {
        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => array(
                    'User-Agent: BRAVE Slack Signup (https://github.com/bravecollective/slack-signup)',
                    'Authorization: Bearer ' . $_ENV['NEUCORE_PLUGIN_SLACK_TOKEN'],
                ),
            ),
        );

        // https://api.slack.com/methods/chat.postMessage
        $url = 'https://slack.com/api/chat.postMessage?' .
            '&channel=' . urlencode($_ENV['NEUCORE_PLUGIN_SLACK_CHANNEL']) .
            '&text=' . urlencode($text);

        file_get_contents($url, false, stream_context_create($options));
    }

    /**
     * @throws Exception
     */
    private function dbConnect(): void
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    $_ENV['NEUCORE_PLUGIN_SLACK_DB_DSN'],
                    $_ENV['NEUCORE_PLUGIN_SLACK_DB_USERNAME'],
                    $_ENV['NEUCORE_PLUGIN_SLACK_DB_PASSWORD']
                );
            } catch (PDOException $e) {
                $this->logger->error($e->getMessage() . ' at ' . __FILE__ . ':' . __LINE__);
                throw new Exception();
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }
}
