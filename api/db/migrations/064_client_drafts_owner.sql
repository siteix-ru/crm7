UPDATE `request_drafts` d
  INNER JOIN `users` u ON u.`ID` = d.`user_id`
SET d.`client_id` = d.`user_id`
WHERE d.`client_id` IS NULL AND u.`LEVEL` = 5;
