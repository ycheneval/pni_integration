-- Function: salesforcetraining2.datediff(character, timestamp without time zone, timestamp without time zone)

-- DROP FUNCTION salesforcetraining2.datediff(character, timestamp without time zone, timestamp without time zone);

CREATE OR REPLACE FUNCTION salesforcetraining2.datediff(time_length character, start_time timestamp without time zone, end_time timestamp without time zone)
  RETURNS integer AS
$BODY$
DECLARE
	result integer;
BEGIN
	CASE time_length
		WHEN 'mi', 'minute', 'n' THEN
			result := (SELECT (DATE_PART('day', end_time - start_time) * 24 + 
			DATE_PART('hour', end_time - start_time)) * 60 +
			DATE_PART('minute', end_time - start_time));
		ELSE
			result := 0;
		END CASE;
	RETURN result;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100;
ALTER FUNCTION salesforcetraining2.datediff(character, timestamp without time zone, timestamp without time zone)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.datediff(character, timestamp without time zone, timestamp without time zone) IS 'datediff in pl/sql';
