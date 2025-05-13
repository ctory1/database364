Make sure you clone the database properly

do this by clicking the clone button and then copying the command into your terminal

First, run this command in your terminal: /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
Next, run the commands that the terminal prompts you to run after homebrew is installed
Then, run this command: brew install mysql
Next, run this command: brew services start mysql (this command will start running mysql)
After that, you need to download the connector from PHP to mysql, run this command:
After that, you need to download the connector from PHP to mysql, run this command: brew install pecl
Then run: pecl install pdo_php
Now you need to navigate to the reel-deal-database on your local host: cd reel-deal-database
Once you have done that, you need to open the index.php and actors.php and change your username and password


Once you have completed these steps, make sure mysql is running: brew services start mysql
Now follow the step below
Run these PHP scripts on your local machine..
In terminal do the following:

cd reel-deal-database
php -S localhost:8000

Once you have done that, go to your browser and search up: http://localhost:8000
Enjoy!
Creating a database to store Movies and have queries in SQL that find movies based on recommendations.
