-- PostgreSQL version of the supply chain database
-- Create database
CREATE DATABASE IF NOT EXISTS nsreekan;

-- Create ENUM types first
CREATE TYPE role_enum AS ENUM('SupplyChainManager', 'SeniorManager');
CREATE TYPE tier_level_enum AS ENUM('1', '2', '3');
CREATE TYPE company_type_enum AS ENUM('Manufacturer', 'Distributor', 'Retailer');
CREATE TYPE product_category_enum AS ENUM('Electronics', 'Raw Material', 'Component', 'Finished Good', 'Other');
CREATE TYPE transaction_type_enum AS ENUM('Shipping', 'Receiving', 'Adjustment');
CREATE TYPE quarter_enum AS ENUM('Q1', 'Q2', 'Q3', 'Q4');
CREATE TYPE impact_level_enum AS ENUM('Low', 'Medium', 'High');

-- Create tables
CREATE TABLE "User" (
    UserID SERIAL PRIMARY KEY,
    FullName VARCHAR(100) NOT NULL,
    Username VARCHAR(50) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    Role role_enum NOT NULL
);

CREATE TABLE Location (
    LocationID SERIAL PRIMARY KEY,
    CountryName VARCHAR(100) NOT NULL,
    ContinentName VARCHAR(50) NOT NULL,
    City VARCHAR(100) NOT NULL,
    UNIQUE (CountryName, City)
);

CREATE TABLE Company (
    CompanyID SERIAL PRIMARY KEY,
    CompanyName VARCHAR(100) NOT NULL UNIQUE,
    LocationID INT NOT NULL,
    TierLevel tier_level_enum NOT NULL DEFAULT '3',
    Type company_type_enum NOT NULL,
    FOREIGN KEY (LocationID) REFERENCES Location(LocationID) ON UPDATE CASCADE
);

CREATE TABLE Manufacturer (
    CompanyID INT PRIMARY KEY,
    FactoryCapacity INT NOT NULL DEFAULT 0,
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);

CREATE TABLE Distributor (
    CompanyID INT PRIMARY KEY,
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);

CREATE TABLE Retailer (
    CompanyID INT PRIMARY KEY,
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);

CREATE TABLE Product (
    ProductID SERIAL PRIMARY KEY,
    ProductName VARCHAR(100) NOT NULL UNIQUE,
    Category product_category_enum NOT NULL
);

CREATE TABLE InventoryTransaction (
    TransactionID SERIAL PRIMARY KEY,
    Type transaction_type_enum NOT NULL
);

CREATE TABLE Shipping (
    ShipmentID SERIAL PRIMARY KEY,
    TransactionID INT NOT NULL,
    DistributorID INT NOT NULL,
    ProductID INT NOT NULL,
    SourceCompanyID INT NOT NULL,
    DestinationCompanyID INT NOT NULL,
    PromisedDate DATE NOT NULL,
    ActualDate DATE,
    Quantity INT NOT NULL,
    FOREIGN KEY (TransactionID) REFERENCES InventoryTransaction(TransactionID) ON UPDATE CASCADE,
    FOREIGN KEY (DistributorID) REFERENCES Distributor(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (ProductID) REFERENCES Product(ProductID) ON UPDATE CASCADE,
    FOREIGN KEY (SourceCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (DestinationCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);

CREATE TABLE Receiving (
    ReceivingID SERIAL PRIMARY KEY,
    TransactionID INT NOT NULL,
    ShipmentID INT NOT NULL,
    ReceiverCompanyID INT NOT NULL,
    ReceivedDate DATE NOT NULL,
    QuantityReceived INT NOT NULL,
    FOREIGN KEY (TransactionID) REFERENCES InventoryTransaction(TransactionID) ON UPDATE CASCADE,
    FOREIGN KEY (ShipmentID) REFERENCES Shipping(ShipmentID) ON UPDATE CASCADE,
    FOREIGN KEY (ReceiverCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);

CREATE TABLE InventoryAdjustment (
    AdjustmentID SERIAL PRIMARY KEY,
    TransactionID INT NOT NULL,
    CompanyID INT NOT NULL,
    ProductID INT NOT NULL,
    AdjustmentDate DATE NOT NULL,
    QuantityChange INT NOT NULL,
    Reason VARCHAR(100),
    FOREIGN KEY (TransactionID) REFERENCES InventoryTransaction(TransactionID) ON UPDATE CASCADE,
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (ProductID) REFERENCES Product(ProductID) ON UPDATE CASCADE
);

CREATE TABLE FinancialReport (
    CompanyID INT NOT NULL,
    Quarter quarter_enum NOT NULL,
    RepYear INTEGER NOT NULL,
    HealthScore DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (CompanyID, Quarter, RepYear),
    FOREIGN KEY (CompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);

CREATE TABLE DisruptionCategory (
    CategoryID SERIAL PRIMARY KEY,
    CategoryName VARCHAR(100) NOT NULL UNIQUE,
    Description VARCHAR(255)
);

CREATE TABLE DisruptionEvent (
    EventID SERIAL PRIMARY KEY,
    EventDate DATE NOT NULL,
    EventRecoveryDate DATE NULL,
    CategoryID INT NOT NULL,
    FOREIGN KEY (CategoryID) REFERENCES DisruptionCategory(CategoryID) ON UPDATE CASCADE
);

CREATE TABLE DependsOn (
    UpstreamCompanyID INT NOT NULL,
    DownstreamCompanyID INT NOT NULL,
    PRIMARY KEY (UpstreamCompanyID, DownstreamCompanyID),
    FOREIGN KEY (UpstreamCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (DownstreamCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);

CREATE TABLE SuppliesProduct (
    SupplierID INT NOT NULL,
    ProductID INT NOT NULL,
    SupplyPrice DECIMAL(10, 2),
    PRIMARY KEY (SupplierID, ProductID),
    FOREIGN KEY (SupplierID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (ProductID) REFERENCES Product(ProductID) ON UPDATE CASCADE
);

CREATE TABLE ImpactsCompany (
    EventID INT NOT NULL,
    AffectedCompanyID INT NOT NULL,
    ImpactLevel impact_level_enum NOT NULL,
    PRIMARY KEY (EventID, AffectedCompanyID),
    FOREIGN KEY (EventID) REFERENCES DisruptionEvent(EventID) ON UPDATE CASCADE,
    FOREIGN KEY (AffectedCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);

CREATE TABLE OperatesLogistics (
    DistributorID INT NOT NULL,
    FromCompanyID INT NOT NULL,
    ToCompanyID INT NOT NULL,
    PRIMARY KEY (DistributorID, FromCompanyID, ToCompanyID),
    FOREIGN KEY (DistributorID) REFERENCES Distributor(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (FromCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE,
    FOREIGN KEY (ToCompanyID) REFERENCES Company(CompanyID) ON UPDATE CASCADE
);