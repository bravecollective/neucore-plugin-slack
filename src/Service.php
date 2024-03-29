<?php

namespace Brave\Neucore\Plugin\Slack;

use Neucore\Plugin\Core\FactoryInterface;
use Neucore\Plugin\Data\CoreAccount;
use Neucore\Plugin\Data\CoreCharacter;
use Neucore\Plugin\Data\CoreGroup;
use Neucore\Plugin\Data\PluginConfiguration;
use Neucore\Plugin\Data\ServiceAccountData;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceInterface;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/** @noinspection PhpUnused */
class Service implements ServiceInterface
{
    private const STATUS_ACTIVE = 'Active';

    private const STATUS_TERMINATED = 'Terminated';

    private const STATUS_PENDING_REMOVAL = 'Pending Removal';

    private LoggerInterface $logger;

    private ?PDO $pdo = null;

    public function __construct(
        LoggerInterface $logger,
        PluginConfiguration $pluginConfiguration,
        FactoryInterface $factory,
    ) {
        $this->logger = $logger;
    }

    public function onConfigurationChange(): void
    {
    }

    /**
     * @throws Exception
     */
    public function request(
        string $name,
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?CoreAccount $coreAccount,
    ): ResponseInterface {
        throw new Exception();
    }

    /**
     * @param CoreCharacter[] $characters
     * @return ServiceAccountData[]
     * @throws Exception
     */
    public function getAccounts(array $characters): array
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
            "SELECT character_id, email, invited_at, slack_id, account_status, slack_name 
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
            if (
                in_array($row['account_status'], [self::STATUS_ACTIVE, self::STATUS_PENDING_REMOVAL]) &&
                $row['slack_id'] !== null
            ) {
                $status = ServiceAccountData::STATUS_ACTIVE;
            } elseif ($row['invited_at'] > $this->getInviteWaitTime(3)) {
                $status = ServiceAccountData::STATUS_PENDING;
            } elseif ($row['account_status'] === self::STATUS_TERMINATED && $row['slack_id'] !== null) {
                $status = ServiceAccountData::STATUS_DEACTIVATED;
            }
            $result[] = new ServiceAccountData(
                characterId: (int)$row['character_id'],
                username: "ID {$row['slack_id']}",
                email: $row['email'],
                status: $status,
                name: $row['slack_name'] ?? null
            );
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
        } catch(PDOException) {
            throw new Exception();
        }
        if (!$emailOk) {
            throw new Exception(self::ERROR_EMAIL_MISMATCH);
        }

        // add or update account
        $stmt = $this->pdo->prepare('SELECT email, email_history, invited_at FROM invite WHERE character_id = :id');
        try {
            $stmt->execute([':id' => $character->id]);
        } catch(PDOException) {
            throw new Exception();
        }
        if (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($row['invited_at'] > $this->getInviteWaitTime()) {
                throw new Exception(self::ERROR_INVITE_WAIT);
            }
            $update = $this->pdo->prepare(
                'UPDATE invite 
                SET email = :email, invited_at = :invited_at, email_history = :email_history, slack_id = null
                WHERE character_id = :character_id'
            );
            $update->bindValue(':email', $emailAddress);
            $update->bindValue(':invited_at', time());
            $update->bindValue(':email_history', $row['email_history'] . ", " . $row['email']);
            $update->bindValue(':character_id', $character->id);
            try {
                $update->execute();
            } catch(PDOException) {
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
            } catch(PDOException) {
                throw new Exception();
            }
        }

        $this->sendSlack("$character->name <$emailAddress>");

        return new ServiceAccountData($character->id, null, null, $emailAddress);
    }

    public function updateAccount(CoreCharacter $character, array $groups, ?CoreCharacter $mainCharacter): void
    {
        throw new Exception();
    }

    public function updatePlayerAccount(CoreCharacter $mainCharacter, array $groups): void
    {
        throw new Exception();
    }

    public function moveServiceAccount(int $toPlayerId, int $fromPlayerId): bool
    {
        return true;
    }

    public function resetPassword(int $characterId): string
    {
        throw new Exception();
    }

    public function getAllAccounts(): array
    {
        throw new Exception();
    }

    public function getAllPlayerAccounts(): array
    {
        throw new Exception();
    }

    public function search(string $query): array
    {
        $this->dbConnect();

        $query = str_replace(['\\', '_', '%'], ['\\\\', '\_', '\%'], $query);
        $stmt = $this->pdo->prepare( 'SELECT character_id FROM invite WHERE slack_name LIKE ? OR slack_id LIKE ?');
        try {
            $stmt->execute(["%$query%", "%$query%"]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return array_map(function (array $row) {
            return new ServiceAccountData((int)$row['character_id']);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function getInviteWaitTime(int $multiplyWaitTime = 1): int
    {
        return time() - (60 * 60 * 12 * $multiplyWaitTime);
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
            $stmt->execute([':email' => $emailAddress, ':account_status' => ServiceAccountData::STATUS_ACTIVE]);
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

    private function sendSlack($text): void
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
