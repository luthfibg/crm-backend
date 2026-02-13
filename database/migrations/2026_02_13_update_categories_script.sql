-- Script untuk Update Category Names & Add Source Column
-- Tanggal: 2026-02-13

-- Step 1: Add source column (via migration)
-- Run: php artisan migrate

-- Step 2: Update existing category names
UPDATE customers 
SET category = 'Corporate' 
WHERE category = 'Web Inquiry Corporate';

UPDATE customers 
SET category = 'C&I' 
WHERE category = 'Web Inquiry CNI';

-- Step 3: Set default source for existing Corporate & C&I customers
-- Asumsikan semua existing Corporate & C&I customers datang dari Web Inquiry
UPDATE customers 
SET source = 'Web Inquiry' 
WHERE category IN ('Corporate', 'C&I') 
AND source IS NULL;

-- Verification queries
SELECT category, source, COUNT(*) as total 
FROM customers 
GROUP BY category, source 
ORDER BY category, source;

SELECT * FROM customers 
WHERE category IN ('Corporate', 'C&I') 
LIMIT 10;
