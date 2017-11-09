# Magento Data Upload and Product Creation

This example file is intended to be run from command line to import data quickly into Magento. While other systems are available for data import I have found it necessary to write configuration files to manipulate the data that is stored depending on the CSV data supplied by a client.

## The Configuration file

The config files represent a way to quickly alter the import script to account for any number of variables such as currency, value conversions and multiple dynamic category generation.

## The Data files

Standard CSV files with specific headings supplied to the client

## The Import Process

During the data read-in the attributes and values defined in the CSV is interrogated to make sure Magento has reference to use it. if any value is not found, it is created and a flag is set to prevent any insertion/update. This prevents any duplicate attributes or values being set and ensures that all data being saved is stored in Magento before any attempt is made.

The import script and configuration files represent an ongoing organic process that has resulted from the client supplying changeable data and a way of accounting for unknown or unspecified data.

## Running the script

From the command line navigate to /account/public_html and run the command:

*php shell_data_import.php ../config/alloyconfig.json ../uploads/CSVTemplate-AlloyWheels-TSW.csv

And optional third parameter can be passed in which helps to paginate data:

*php shell_data_import.php ../config/tyreconfig.json ../uploads/tyredata.csv 2
