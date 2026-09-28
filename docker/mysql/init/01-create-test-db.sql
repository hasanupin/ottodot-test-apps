-- Runs once, on the first start of a fresh mysql-data volume.
CREATE DATABASE IF NOT EXISTS ottodot_test;
GRANT ALL PRIVILEGES ON ottodot_test.* TO 'ottodot'@'%';
FLUSH PRIVILEGES;
