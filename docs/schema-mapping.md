# KhetSaathi database schema mapping

## Evidence and scope

This mapping records the verified schema and database facts supplied for the existing `KhetSaathi` database. No database command was run for this update. Details not included in the supplied facts are identified as **not specified**; they are not inferred.

The existing database and its sample records must be preserved. This document does not propose schema changes.

## Tables, columns, keys, and constraints

`NULL` and `NOT NULL` below reflect the supplied column definitions. Defaults, indexes, foreign keys, and checks are listed only where specified.

### `Farmer`

| Column | Definition | Key / constraints |
|---|---|---|
| `Farmer_ID` | `INT AUTO_INCREMENT NOT NULL` | Primary key |
| `Farmer_Name` | `VARCHAR(100) NOT NULL` | — |
| `Contact_Number` | `VARCHAR(20) NULL` | Unique |
| `Location_Description` | `VARCHAR(255) NULL` | — |

### `Soil_Type`

| Column | Definition | Key / constraints |
|---|---|---|
| `Soil_ID` | `INT AUTO_INCREMENT NOT NULL` | Primary key |
| `Soil_Name` | `VARCHAR(50) NOT NULL` | Unique |
| `Description` | `TEXT NULL` | — |

### `Field`

| Column | Definition | Key / constraints |
|---|---|---|
| `Field_ID` | `INT AUTO_INCREMENT NOT NULL` | Primary key |
| `Farmer_ID` | `INT NOT NULL` | Foreign key to `Farmer(Farmer_ID)` |
| `Field_Name` | `VARCHAR(100) NOT NULL` | — |
| `Field_Location` | `VARCHAR(255) NOT NULL` | — |
| `Soil_ID` | `INT NULL` | Foreign key to `Soil_Type(Soil_ID)` |
| `Fertility_Level` | `ENUM('High','Medium','Low') NULL` | Allowed values: `High`, `Medium`, `Low` |

### `Crop`

| Column | Definition | Key / constraints |
|---|---|---|
| `Crop_ID` | `INT AUTO_INCREMENT NOT NULL` | Primary key |
| `Crop_Name` | `VARCHAR(100) NOT NULL` | Unique |
| `Crop_Category` | `VARCHAR(50) NULL` | — |
| `General_Information` | `TEXT NULL` | — |

### `Crop_Guide`

| Column | Definition | Key / constraints |
|---|---|---|
| `Crop_ID` | `INT NOT NULL` | Primary key; foreign key to `Crop(Crop_ID)`; at most one guide row per crop |
| `Temperature_Min_C` | `DECIMAL(5,2) NULL` | — |
| `Temperature_Max_C` | `DECIMAL(5,2) NULL` | — |
| `Rainfall_Min_mm` | `DECIMAL(8,2) NULL` | — |
| `Rainfall_Max_mm` | `DECIMAL(8,2) NULL` | — |
| `Water_Requirement` | `ENUM('Low','Moderate','High') NULL` | Allowed values: `Low`, `Moderate`, `High` |
| `Growing_Duration_Min_Days` | `INT NULL` | — |
| `Growing_Duration_Max_Days` | `INT NULL` | — |
| `Source_Name` | `VARCHAR(255) NULL` | — |
| `Source_URL` | `VARCHAR(500) NULL` | — |

Check constraints ensure each minimum does not exceed its corresponding maximum when both are present: temperature minimum/maximum, rainfall minimum/maximum, and growing-duration minimum/maximum. Specific check-constraint names were not supplied.

### `Season`

| Column | Definition | Key / constraints |
|---|---|---|
| `Season_ID` | `INT AUTO_INCREMENT NOT NULL` | Primary key |
| `Season_Name` | `VARCHAR(50) NOT NULL` | Unique |

### `Crop_Season`

| Column | Definition | Key / constraints |
|---|---|---|
| `Crop_ID` | `INT NOT NULL` | Part of composite primary key; foreign key to `Crop(Crop_ID)` |
| `Season_ID` | `INT NOT NULL` | Part of composite primary key; foreign key to `Season(Season_ID)` |
| `Notes` | `VARCHAR(500) NULL` | — |

Composite primary key: (`Crop_ID`, `Season_ID`).

### `Crop_Soil_Suitability`

| Column | Definition | Key / constraints |
|---|---|---|
| `Crop_ID` | `INT NOT NULL` | Part of composite primary key; foreign key to `Crop(Crop_ID)` |
| `Soil_ID` | `INT NOT NULL` | Part of composite primary key; foreign key to `Soil_Type(Soil_ID)` |
| `Suitability_Notes` | `VARCHAR(500) NULL` | — |

Composite primary key: (`Crop_ID`, `Soil_ID`).

### `Planting_Record`

| Column | Definition | Key / constraints |
|---|---|---|
| `Planting_ID` | `INT AUTO_INCREMENT NOT NULL` | Primary key |
| `Field_ID` | `INT NOT NULL` | Foreign key to `Field(Field_ID)` |
| `Crop_ID` | `INT NOT NULL` | Foreign key to `Crop(Crop_ID)` |
| `Season_ID` | `INT NOT NULL` | Foreign key to `Season(Season_ID)` |
| `Planting_Year` | `YEAR NOT NULL` | — |
| `Planting_Date` | `DATE NULL` | — |
| `Planting_Status` | `ENUM('Planned','Planted','Completed') NULL` | Default `Planned`; allowed values: `Planned`, `Planted`, `Completed` |

No known unique constraint exists on (`Field_ID`, `Crop_ID`, `Season_ID`, `Planting_Year`). Other indexes or constraints were not specified.

### `Support_Request`

| Column | Definition | Key / constraints |
|---|---|---|
| `Request_ID` | `INT AUTO_INCREMENT NOT NULL` | Primary key |
| `Field_ID` | `INT NOT NULL` | Foreign key to `Field(Field_ID)` |
| `Original_Message` | `TEXT NOT NULL` | Original submitted message; must not be overwritten by simplified text |
| `Simplified_Message` | `TEXT NULL` | Optional simplified display text |
| `Submitted_At` | `TIMESTAMP NOT NULL` | Default `CURRENT_TIMESTAMP` |
| `Urgency` | `ENUM('Low','Medium','High') NULL` | Default `Medium`; allowed values: `Low`, `Medium`, `High` |
| `Status` | `ENUM('Pending','Under Review','Resolved') NOT NULL` | Default `Pending`; allowed values: `Pending`, `Under Review`, `Resolved` |

Known index: `idx_support_request_fifo` on (`Submitted_At`, `Request_ID`). The `Field_ID` foreign key references `Field(Field_ID)`. A support submitter's farmer identity is derived through `Support_Request -> Field -> Farmer`; `Support_Request` has no separate `Farmer_ID` column.

## Verified relationships

| Relationship | Verified link |
|---|---|
| Farmer to fields | `Field.Farmer_ID` references `Farmer.Farmer_ID` |
| Soil type to fields | `Field.Soil_ID` references `Soil_Type.Soil_ID` (nullable on `Field`) |
| Crop to guide | `Crop_Guide.Crop_ID` references `Crop.Crop_ID`; because it is the guide table primary key, at most one guide row exists per crop |
| Crop to seasons | `Crop_Season.Crop_ID` references `Crop.Crop_ID` |
| Season to crop-season association | `Crop_Season.Season_ID` references `Season.Season_ID` |
| Crop to soil-suitability association | `Crop_Soil_Suitability.Crop_ID` references `Crop.Crop_ID` |
| Soil type to crop-suitability association | `Crop_Soil_Suitability.Soil_ID` references `Soil_Type.Soil_ID` |
| Field to planting records | `Planting_Record.Field_ID` references `Field.Field_ID` |
| Crop to planting records | `Planting_Record.Crop_ID` references `Crop.Crop_ID` |
| Season to planting records | `Planting_Record.Season_ID` references `Season.Season_ID` |
| Field to support requests | `Support_Request.Field_ID` references `Field.Field_ID` |
| Support request to farmer identity | Derived join path: `Support_Request.Field_ID -> Field.Farmer_ID -> Farmer.Farmer_ID`; there is no direct `Support_Request.Farmer_ID` |

The supplied verification confirms foreign-key enforcement: an insert using a nonexistent `Farmer_ID` was rejected. Specific `ON DELETE` / `ON UPDATE` rules were not supplied and are not assumed here.

## Existing allowed values and relevant defaults

| Column | Allowed values | Nullability / default |
|---|---|---|
| `Field.Fertility_Level` | `High`, `Medium`, `Low` | Nullable; no default supplied |
| `Crop_Guide.Water_Requirement` | `Low`, `Moderate`, `High` | Nullable; no default supplied |
| `Planting_Record.Planting_Status` | `Planned`, `Planted`, `Completed` | Nullable; default `Planned` |
| `Support_Request.Urgency` | `Low`, `Medium`, `High` | Nullable; default `Medium` |
| `Support_Request.Status` | `Pending`, `Under Review`, `Resolved` | Not nullable; default `Pending` |

Season names, crop names/categories, soil names, and any other domain value lists are data rather than enum values in the supplied schema details; their complete allowed sets were not provided.

## Sample data facts supplied

| Table | Existing sample count |
|---|---:|
| `Farmer` | 3 |
| `Field` | 3 |
| `Crop` | 15 |
| `Crop_Guide` | 15 |
| `Planting_Record` | 2 |
| `Support_Request` | 2 |

Counts for the other tables were not supplied. These are existing sample records and must be preserved.

## Planned application feature mapping

| Application feature | Tables and verified mapping |
|---|---|
| Farmer management | `Farmer`; list/create/update farmer details. `Contact_Number` is nullable and unique when supplied. |
| Field management | `Field`, joined to `Farmer`; optionally join `Soil_Type` through nullable `Field.Soil_ID`. `Fertility_Level` is stored on `Field`; there is no historical fertility table. |
| Crop catalogue and general information | `Crop`; optional one-to-one `Crop_Guide` via `Crop_ID`. Guide ranges and water requirement are nullable and provisional demo data. |
| Crop season display | `Crop_Season` joined with `Crop` and `Season`; association notes are nullable. |
| Crop/soil association display | `Crop_Soil_Suitability` joined with `Crop` and `Soil_Type`; suitability notes are nullable. |
| Planting-record management | `Planting_Record` joined with `Field`, `Crop`, and `Season`. A field's farmer can be derived by joining through `Field`. No known uniqueness rule prevents multiple records for the same field/crop/season/year combination. |
| Support submission and tracking | `Support_Request` joined with `Field`; derive farmer identity through `Field.Farmer_ID`. Keep `Original_Message` intact; `Simplified_Message` is a separate nullable field. |
| Support processing queue | PHP FIFO queue populated from eligible support requests in `Submitted_At ASC, Request_ID ASC` order. The database also has `idx_support_request_fifo` on those columns. Actual queue `enqueue`, `dequeue`, `front`, and `isEmpty` operations must be implemented in PHP; SQL ordering alone is not the queue. |
| Undo admin status change | PHP stack records request ID plus previous and new `Status`. Undo should validate that the database's current status equals the recorded new status, then restore the previous status in a transaction. The status column is `Support_Request.Status`. |
| Crop hash lookup | PHP hash table indexes crop data by ID and/or name, using separate chaining for collisions. Crop identity columns are `Crop_ID` and unique `Crop_Name`. |
| Recent activity | PHP singly linked list, with new events inserted at the head. The planned list is in-memory for the current session and is not persistent after the session ends. No activity table was supplied. |

## Implementation assumptions, limitations, and unknowns

1. All PHP database access should use PDO and prepared statements for values. Credentials remain outside tracked source files and outside browser code.
2. Never drop, truncate, reset, or recreate the existing database or tables; preserve all sample data.
3. The supplied schema details do not include all indexes, check names, storage engine, or foreign-key `ON DELETE` / `ON UPDATE` actions. Do not assume these when implementing transactions or delete behavior.
4. Queue ordering is specified as `Submitted_At ASC, Request_ID ASC` and the corresponding composite index is verified. Which statuses are considered eligible to enter the processing queue should be confirmed as an application workflow decision; the schema alone does not define that eligibility rule.
5. Undo is planned around a PHP status-change stack and an in-transaction comparison with the current database status. A PHP in-memory stack does not survive application/session loss; persistent undo history would require a separate approved design.
6. Crop guide duration and water values are provisional demo data, not verified agronomic recommendations. Seasonal and soil associations are general/illustrative and vary by region and crop variety. Present them as indicative information, not authoritative advice.
7. Never replace or mutate `Original_Message` to store simplified support-request text. Use `Simplified_Message` when supplied, while retaining the original separately.
8. A null `Planting_Status` is allowed by the supplied definition despite its `Planned` default; application validation should decide whether to require a value on new records without assuming the database disallows null.
9. The supplied facts do not specify authentication/authorization, CSRF handling, exact validation limits beyond column types, or how status-change history is scoped. These require application-level decisions before implementation.
