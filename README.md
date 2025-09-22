# Vendetta Plus - Refactored

This is a refactored version of the Vendetta Plus game, with only the essential files for the `vendetta_old` server.

## Overview

This project has been reorganized to isolate the `vendetta_old` game. Unnecessary files and directories, such as the `space4k` game and other public entry points, have been removed. The codebase has been cleaned to remove all logic related to the `space4k` game.

A `schema.sql` file has been created with the SQL statements for the main database tables, allowing you to quickly set up the required database.

## Setup Instructions

### 1. Prerequisites

*   A web server with PHP 5.x (e.g., Apache, Nginx).
*   A MySQL database server.

### 2. Library Setup (Zend Framework)

The project depends on the Zend Framework 1, but due to the large number of files, it is not included in this repository. You will need to download it and place it in the `library/` directory.

**a. Remove the placeholder `Zend` directory (if it exists):**

The original `library/Zend` directory could not be removed automatically. Please remove it manually if it's still present:

```bash
rm -rf library/Zend
```

**b. Download Zend Framework 1:**

The game was likely developed with a version of Zend Framework 1. The last version was 1.12.20. You can try to find this version online. A good place to start is the official Zend Framework blog or GitHub repositories.

*   [Zend Framework 1 End-of-Life Announcement](https://framework.zend.com/blog/2016-06-28-zf1-eol.html)

**c. Install the framework:**

Once you have downloaded the framework, extract the archive. You will find a `library/Zend` directory inside. Copy this `Zend` directory into the `library/` directory of this project. The final structure should look like this:

```
/
|-- application/
|-- library/
|   |-- Mob/
|   |-- Zend/  <-- The directory you just copied
|-- public/
|-- ...
```

### 3. Database Setup

1.  Create a new MySQL database. The application is configured to use the name `vendetta_plus_old`.

    ```sql
    CREATE DATABASE vendetta_plus_old;
    ```

2.  Import the provided `schema.sql` file to create the main tables for the game.

    ```bash
    mysql -u your_username -p vendetta_plus_old < schema.sql
    ```
    *Replace `your_username` with your MySQL username.*

### 4. Application Configuration

1.  The main configuration file is `application/configs/application.ini`.
2.  The application is set to run in `development` mode (in `public/index.php`).
3.  The development database configuration is in the `[development : base]` section of the `.ini` file. By default, it's configured to use the `root` user with an empty password. Adjust this to match your local MySQL setup if needed.

    ```ini
    [development : base]
    ; ...
    resources.db.params.username = "root"
    resources.db.params.password = ""
    ```

### 5. Running the Game

1.  Configure your web server (e.g., Apache) to use the `public/` directory of this project as the document root for a new virtual host.
2.  Make sure the `AllowOverride` directive is set to `All` for the `public/` directory in your Apache configuration, so that the `.htaccess` file (if any) can be processed.
3.  Open your web browser and navigate to the local URL you configured for your virtual host.

You should now be able to see and interact with the Vendetta Plus game.
