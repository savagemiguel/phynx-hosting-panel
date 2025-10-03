-- Docker integration schema (idempotent)

-- Images registry (admin-managed, optional whitelist)
CREATE TABLE IF NOT EXISTS `docker_images` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,              -- repo:tag
  `status` ENUM('active','blocked') DEFAULT 'active',
  `source` ENUM('official','custom','private') DEFAULT 'official',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `ux_image_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-curated deployment templates (single container or compose)
CREATE TABLE IF NOT EXISTS `docker_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `type` ENUM('single','compose') NOT NULL DEFAULT 'compose',
  `yaml` MEDIUMTEXT NULL,                    -- docker-compose YAML or serialized config
  `defaults` MEDIUMTEXT NULL,                -- JSON defaults
  `allowed` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `ux_template_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User containers (single, non-compose)
CREATE TABLE IF NOT EXISTS `docker_containers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `image` VARCHAR(255) NOT NULL,
  `container_id` VARCHAR(128) DEFAULT NULL,  -- Docker container ID
  `status` VARCHAR(50) DEFAULT NULL,         -- running, exited, etc.
  `ports` MEDIUMTEXT NULL,                   -- JSON [{host,container,proto}]
  `env` MEDIUMTEXT NULL,                     -- JSON {KEY:VALUE}
  `mounts` MEDIUMTEXT NULL,                  -- JSON [{host,container,ro}]
  `cpu_limit` VARCHAR(32) DEFAULT NULL,      -- e.g., "1.0"
  `mem_limit` VARCHAR(32) DEFAULT NULL,      -- e.g., "512m"
  `network` VARCHAR(128) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_docker_containers_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `ux_docker_containers_user_name` (`user_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User stacks (compose projects)
CREATE TABLE IF NOT EXISTS `docker_stacks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `compose_file_path` VARCHAR(600) NOT NULL,
  `env` MEDIUMTEXT NULL,                     -- JSON of variable assignments
  `status` VARCHAR(50) DEFAULT NULL,         -- up, down, etc.
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_docker_stacks_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `ux_docker_stacks_user_slug` (`user_id`,`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional per-user networks (if managing explicitly)
CREATE TABLE IF NOT EXISTS `docker_networks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `driver` VARCHAR(50) DEFAULT 'bridge',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_docker_networks_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `ux_docker_networks_user_name` (`user_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Port allocation to avoid conflicts
CREATE TABLE IF NOT EXISTS `docker_ports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `container_id_ref` INT NULL,               -- ref to docker_containers.id
  `stack_id_ref` INT NULL,                   -- ref to docker_stacks.id
  `host_port` INT NOT NULL,
  `container_port` INT NOT NULL,
  `proto` ENUM('tcp','udp') DEFAULT 'tcp',
  `allocated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_docker_ports_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `ix_docker_ports_host_port` (`host_port`),
  UNIQUE KEY `ux_docker_ports_user_hostport_proto` (`user_id`,`host_port`,`proto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit/events log for Docker operations
CREATE TABLE IF NOT EXISTS `docker_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,                        -- null for admin/system
  `action` VARCHAR(100) NOT NULL,            -- pull, run, stop, remove, up, down, prune, etc.
  `target_type` VARCHAR(50) NOT NULL,        -- container, stack, image, network
  `target_name` VARCHAR(255) NOT NULL,       -- human-readable name/slug
  `payload` MEDIUMTEXT NULL,                 -- JSON with parameters and results
  `ip` VARCHAR(64) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `ix_docker_events_user` (`user_id`),
  INDEX `ix_docker_events_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
