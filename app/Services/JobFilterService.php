<?php

namespace App\Services;

use App\Models\Attribute;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JobFilterService
{

    /**
     * this is main entry point for the filter service, takes the query and the filter string
     * i have taken filter string query parameter as given in the doc,
     * /api/jobs?filter=(job_type=full-time AND (languages HAS_ANY (PHP,JavaScript))) AND (locations IS_ANY (New York,Remote)) AND attribute:years_experience>=3
     * as i understood that it should be stert with filter
     * it may have inner parentheses and nested conditions ( grouping )
     * and it should support relationship filtering as well
     * and attribute filtering as well
     *
     * so created difftent methods to handle each case
     *
     *
     * we can change it to have json so we can be able to remove the parsing logic
     * ex : /api/jobs?filter={"AND" : {"job_type":"full-time","languages":["PHP","JavaScript"]},"OR" : {"locations":["New York","Remote"],"attribute":{"OR":{"years_experience":3,'joining_availibility':'immediately'}}}}
     * i understand that GET is limited with characters and we may have long filter string
     * but to remove processing logic from server side we can implement this.
     *
     * also, the best solution would be to use GraphQl to have more control over the query and filtering process.
     * so entirely it will be different approach. and api consumers will be able to decide the filters and the response.
     * and it will be much more efficient and scalable. backend developer can focus on main business logic
     * instead of fixing the filtering issues. because if we add another relation in this, we may need to
     * update our logic class, it may have different filter column name, eg : location have city for filter and other relations have column name as filter
     *
     * @param Builder $query
     * @param string|null $filterString
     * @return Builder
     */
    public static function applyFilters(Builder $query, ?string $filterString): Builder
    {
        if (empty($filterString)) {
            return $query;
        }

        // parse the filter string and apply conditions
        return self::parseFilterGroup($query, $filterString);
    }

    /**
     * this method will parse the filter string do grouping and balancing the brackets so that
     * the conditions can be applied correctly, it will identify the starting bracket and
     * handle by level and then apply the conditions
     *
     * few years back when i was reading about the compilers and interpreters, i got this method
     * that, how a compiler tokenize the code and parse it. so i have used similar logic here, not that complex
     *
     * @param Builder $query
     * @param string $filterString
     * @return Builder
     * @throws Exception
     */
    private static function parseFilterGroup(Builder $query, string $filterString): Builder
    {
        try {
            // trim whitespace
            $filterString = trim($filterString);
            // Safety check for empty string after trimming
            if (empty($filterString)) {
                return $query;
            }

            // Validate parentheses balance before processing
            if (substr_count($filterString, '(') != substr_count($filterString, ')')) {
                Log::warning("Unbalanced parentheses in filter: {$filterString}");
                throw new Exception("Unbalanced parentheses in filter: {$filterString}");
//            return $query;
            }

            // remove the outermost parentheses if present used laravel's Str class, can use php's str_starts_with and str_ends_with
            if (Str::startsWith($filterString, '(') && Str::endsWith($filterString, ')')) {
                // count parentheses to ensure we only remove the outermost ones
                $level = 0;
                $balanced = true;

                // tried to identify with regex but it was taking too much time so i run a loop through filter string to get the parentheses ( and ),  -1 to ignore last char we know it is )
                for ($i = 0; $i < strlen($filterString) - 1; $i++) {
                    if ($filterString[$i] === '(') $level++;
                    if ($filterString[$i] === ')') $level--;

                    // if level drops to 0 before the last character, these aren't outer parentheses
                    if ($level == 0 && $i < strlen($filterString) - 2) {
                        $balanced = false;
                        break;
                    }

                    if ($level < 0) {
                        $balanced = false;
                        break;
                    }
                }

                // only remove if these are balanced outermost parentheses
                if ($balanced && $level == 1) { // Should be 1 because we haven't counted the final ')'
                    $filterString = substr($filterString, 1, -1);
                }
            }

            // find top-level AND/OR operators (not inside parentheses)
            $level = 0;
            $operators = [];

            for ($i = 0; $i < strlen($filterString); $i++) {
                if ($filterString[$i] === '(') $level++;
                if ($filterString[$i] === ')') $level--;

                // only look for operators at the top level (level = 0)
                if ($level == 0) {
                    // check for " AND " or " OR " with a space before and after
                    if (substr($filterString, $i, 5) === ' AND ') {
                        $operators[] = [
                            'type' => 'AND',
                            'position' => $i
                        ];
                        $i += 4; // skip the rest of the operator
                    } elseif (substr($filterString, $i, 4) === ' OR ') {
                        $operators[] = [
                            'type' => 'OR',
                            'position' => $i
                        ];
                        $i += 3; // skip the rest of the operator
                    }
                }
            }

            // if we found operators, split and process recursively with the first operator found
            if (!empty($operators)) {
                $operator = $operators[0]; // use the first operator found
                $position = $operator['position'];
                $type = $operator['type'];
                $length = ($type === 'AND') ? 5 : 4; // with space before and after

                $leftFilter = substr($filterString, 0, $position); // left side filter string of the operator
                $rightFilter = substr($filterString, $position + $length); // right side filter string of the operator

                if ($type === 'AND') {
                    return self::parseFilterGroup($query, $leftFilter) // calling for recursion for left side
                    ->where(function ($q) use ($rightFilter) {
                        return self::parseFilterGroup($q, $rightFilter); // calling for recursion for right side
                    });
                } else {
                    return $query->where(function ($q) use ($leftFilter, $rightFilter) {
                        return self::parseFilterGroup($q, $leftFilter) // calling for recursion for left side
                        ->orWhere(function ($q2) use ($rightFilter) {
                            return self::parseFilterGroup($q2, $rightFilter); // calling for recursion for right side
                        });
                    });
                }
            }
            //no operators found (exit condition), apply condition directly, also when in filter string eg : api/jobs?filter=job_type=contract
            return self::applyCondition($query, $filterString);
        }
        catch (Exception $e) {
            Log::error("Error parsing filter group: " . $e->getMessage());
            throw new Exception("Error parsing filter group: " . $e->getMessage());
        }

    }

    /**
     * this will recieve the condition and apply the filter on the query
     * eg : job_type=contract or languages HAS_ANY (PHP,JavaScript) or attribute:job_post_start_date>=2025-04-11 etc etc
     *
     * @param Builder $query
     * @param string $condition
     * @return Builder
     */
    private static function applyCondition(Builder $query, string $condition): Builder
    {
        // handle EAV attributes
        if (Str::contains($condition, 'attribute:')) { // to filter with EAV it must have prefix "attribute:"
            return self::applyAttributeFilter($query, $condition);
        }

        // handle relationship filters
        // as per doc, i have taken the relationship filter as HAS_ANY, IS_ANY, EXISTS and = to match all relations value should be there
        if (
            Str::contains($condition, ' HAS_ANY ') ||
            Str::contains($condition, ' IS_ANY ') ||
            Str::contains($condition, ' EXISTS') ||
            Str::contains($condition, 'locations') ||
            Str::contains($condition, 'languages') ||
            Str::contains($condition, 'categories')
        ) {
            return self::applyRelationshipFilter($query, $condition);
        }

        // handle basic field filtering
        $operators = ['>=', '<=', '!=', '=', '>', '<', ' LIKE '];
        $operator = null;

        foreach ($operators as $op) {
            if (Str::contains($condition, $op)) {
                $operator = $op;
                break;
            }
        }

        if (!$operator) {
            return $query;
        }

        [$field, $value] = explode($operator, $condition, 2);
        $field = trim($field);
        $value = trim($value, " \t\n\r\0\x0B\"'()"); // cleaning the input
        // Handle different operators
        switch ($operator) {
            case ' LIKE ':
                return $query->where($field, 'LIKE', "%{$value}%");
            case '=':
                // Handle IN operator with multiple values
                if (Str::contains($value, ',')) {
                    $values = explode(',', $value);
                    return $query->whereIn($field, $values);
                }
                return $query->where($field, $value);
            default:
                return $query->where($field, $operator, $value);
        }
    }

    /**
     * in this method we handle the attribute filtering, as we have EAV attributes
     * if filter string starts with attribute: it means we have to filter with EAV attributes
     * used regex to get the attribute name, operator and value
     *
     * @param Builder $query
     * @param string $condition
     * @return Builder
     */
    private static function applyAttributeFilter(Builder $query, string $condition): Builder
    {

        /**
         * the below regex will gives us the full condition string, attribute name, operator and value
         * as we have defined regex groups
         * ([a-zA-Z_]+) this will give arrribute name
         * ([>=<!]+|LIKE|IN) this will give operator
         * (.+) this will give rest of the string as value
         */

        preg_match('/attribute:([a-zA-Z_]+)([>=<!]+|LIKE|IN)(.+)/', $condition, $matches);

        if (count($matches) < 4) {
            Log::warning("Invalid attribute filter format: {$condition}");
            throw new Exception("Invalid attribute filter format: {$condition}");
        }
        $attributeName = $matches[1];
        $operator = $matches[2];
        $value = trim($matches[3], " \t\n\r\0\x0B\"'()"); // cleaning up input values

        /**
         * find the attribute so that we can have type of the attribute which we can use for jobAttribute column filter
         * as we have type in attribute table which is same as column name in jobAttribute table
         * created 5 diffrent columns in jobAttribute table for each type of attribute
         * so that we can filter the job with attribute without handling the casting and other issues with dates and json and boolean
         */

        $attribute = Attribute::where('name', $attributeName)->first();

        if (!$attribute) {
            Log::warning("Unknown attribute in filter: {$attributeName}");
            throw new Exception("Unknown attribute in filter: {$attributeName}");
        }

        return $query->whereHas('jobAttributes', function ($q) use ($attribute, $operator, $value) {
            $q->where('attribute_id', $attribute->id);

            $valueColumn = $attribute->type; // name of column in jobAttribute table
            try {
                switch ($operator) {
                    case ' LIKE ':
                        $q->where($valueColumn, 'LIKE', "%{$value}%");
                        break;
                    case 'IN':
                        $values = explode(',', $value);
                        $q->whereIn($valueColumn, $values);
                        break;
                    default:
                        $q->where($valueColumn, $operator, $value);
                        break;
                }
            }
            catch (\Exception $e) {
                // log database query exceptions
                Log::error("Error applying attribute filter: " . $e->getMessage());
            }
        });
    }

    /**
     * this method will handle the relationship filtering
     * as per doc, i have taken the relationship filter as HAS_ANY, IS_ANY, EXISTS and = to match all relations value should be there
     *
     *
     * @param Builder $query
     * @param string $condition
     * @return Builder
     */
    private static function applyRelationshipFilter(Builder $query, string $condition): Builder
    {
        if (Str::contains($condition, ' HAS_ANY ')) {
            [$relation, $values] = explode(' HAS_ANY ', $condition);
            return self::applyRelationQuery($relation, $values, $query);
        }

        if (Str::contains($condition, ' IS_ANY ')) {
            [$relation, $values] = explode(' IS_ANY ', $condition);
            return self::applyRelationQuery($relation, $values, $query);
        }
        if (Str::contains($condition, '=')) {
            [$relation, $values] = explode('=', $condition);
            return self::applyEqualToRelationQuery($relation, $values, $query);
        }
        if (Str::contains($condition, ' EXISTS')) {
            $relation = trim(str_replace(' EXISTS', '', $condition));
            // Remove any parenthesis from the relation name
            $relation = trim($relation, '() ');
            return $query->has($relation);
        }

        return $query;
    }

    /**
     * to prevent code duplication and to make the code more readable extracted method from applyRelationshipFilter
     * this method will apply the relation filter on the query
     * @param string $relation
     * @param string $values
     * @param Builder $query
     * @return Builder
     */
    private static function applyRelationQuery(string $relation, string $values, Builder $query): Builder
    {
        $relation = trim($relation);
        // Remove any parenthesis from the relation name
        $relation = trim($relation, '() ');
        $values = trim($values, '()');
        $valueArray = explode(',', $values);

        return $query->whereHas($relation, function ($q) use ($relation, $valueArray) {
            if ($relation == "locations") {
                $q->whereIn('city', $valueArray);
            } else {
                $q->whereIn('name', $valueArray);
            }
        });
    }

    /**
     * to prevent code duplication and to make the code more readable extracted method from applyRelationshipFilter
     * this method will apply the relation filter on the query
     * @param string $relation
     * @param string $values
     * @param Builder $query
     * @return Builder
     */
    private static function applyEqualToRelationQuery(string $relation, string $values, Builder $query): Builder
    {
        $relation = trim($relation);
        $values = trim($values, " \t\n\r\0\x0B\"'()");

        // match ALL values - each value must exist in the relation
        $valueArray = explode(',', $values);
        return $query->where(function ($q) use ($relation, $valueArray) {
            foreach ($valueArray as $value) {
                $q->whereHas($relation, function ($subQuery) use ($relation, $value) {
                    if ($relation == "locations") {
                        $subQuery->where('city', $value);
                    } else {
                        $subQuery->where('name', $value);
                    }
                });
            }
        });
    }
}
